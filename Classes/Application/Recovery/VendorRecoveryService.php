<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Recovery;

use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Filesystem\PathNormalizer;
use Vtinnovations\GuardianTypo3\Domain\Process\CommandRequest;
use Vtinnovations\GuardianTypo3\Infrastructure\Process\SymfonyProcessCommandExecutor;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\AtomicDirectorySwitch;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\ComposerEnvironment;

/**
 * The hardened vendor recovery engine. It NEVER touches the live vendor/ until a
 * complete, validated replacement is staged, and it always switches atomically
 * while retaining the previous vendor for rollback.
 *
 * Two paths, both staged + validated + atomically switched:
 *   - rebuild(): the SAFE DEFAULT — `composer install` from the restored
 *     composer.json/composer.lock into an isolated build directory co-located
 *     with the live vendor/ (guaranteed same filesystem, so the switch is a real
 *     atomic rename), then validated, then switched in.
 *   - staged archived vendor: the caller extracts the archived vendor into
 *     {@see stagedVendorPath()}; this service validates it and switches it in.
 *
 * Staging is deliberately co-located with the live vendor (project root, hidden
 * dot-dirs) so `rename()` is atomic on one filesystem — the property that makes
 * the "deleted live vendor before the replacement was ready" incident impossible.
 */
final class VendorRecoveryService
{
    private const BUILD_DIR = '.guardian-recovery';
    private const OLD_PREFIX = '.guardian-old-vendor-';
    private const FAILED_PREFIX = '.guardian-failed-vendor-';

    public function __construct(
        private readonly ComposerEnvironment $composerEnvironment,
        private readonly SymfonyProcessCommandExecutor $executor,
        private readonly AtomicDirectorySwitch $switch,
        private readonly ProjectEnvironmentInterface $environment,
        private readonly PathNormalizer $pathNormalizer,
    ) {
    }

    public function liveVendorPath(): string
    {
        return $this->projectPath() . '/vendor';
    }

    public function buildDir(string $jobId): string
    {
        return $this->projectPath() . '/' . self::BUILD_DIR . '/' . $this->safeId($jobId);
    }

    public function stagedVendorPath(string $jobId): string
    {
        return $this->buildDir($jobId) . '/vendor';
    }

    public function oldVendorPath(string $jobId): string
    {
        return $this->projectPath() . '/' . self::OLD_PREFIX . $this->safeId($jobId);
    }

    /**
     * Whether the atomic switch is possible for this job (same filesystem). A
     * false result MUST be a blocking preflight error — never a recursive copy.
     */
    public function canAtomicallySwitch(string $jobId): bool
    {
        $project = $this->projectPath();

        return $this->switch->canAtomicallyRename($project . '/vendor', $project . '/' . self::BUILD_DIR)
            && $this->switch->canAtomicallyRename($project . '/vendor', $this->oldVendorPath($jobId));
    }

    /**
     * SAFE DEFAULT: rebuild vendor/ with `composer install` into staging.
     * Leaves a validated {@see stagedVendorPath()} ready to switch in.
     *
     * @param callable(string $level, string $line): void $log
     * @throws GuardianException on any validation or process failure
     */
    public function rebuild(string $jobId, callable $log): void
    {
        $php = $this->composerEnvironment->phpBinary();
        $composer = $this->composerEnvironment->composerBinary();
        if ($php === null) {
            throw new GuardianException('No PHP CLI binary is configured for the vendor rebuild.');
        }
        if ($composer === null) {
            throw new GuardianException('No composer.phar was found for the vendor rebuild.');
        }
        $this->assertComposerFilesValid();

        $build = $this->prepareBuildDir($jobId, $log);

        $log('info', 'Running composer install in the isolated staging directory (live vendor/ is untouched)…');
        $request = CommandRequest::create(
            [$php, $composer, 'install', '--no-interaction', '--no-progress', '--no-scripts', '--optimize-autoloader', '--working-dir=' . $build],
            $build,
            1800,
        );
        $result = $this->executor->runStreaming($request, $log);
        if ($result->exitCode === SymfonyProcessCommandExecutor::EXIT_TIMEOUT) {
            throw new GuardianException('composer install timed out during the staged vendor rebuild.');
        }
        if (!$result->isSuccessful()) {
            throw new GuardianException('composer install failed (exit ' . $result->exitCode . ') — live vendor/ was not changed.');
        }

        $this->validateStagedVendor($jobId, $log);
    }

    /**
     * Validates a staged vendor tree (used for both rebuild and archived paths).
     *
     * @param callable(string $level, string $line): void $log
     * @throws GuardianException
     */
    public function validateStagedVendor(string $jobId, callable $log): void
    {
        $vendor = $this->stagedVendorPath($jobId);
        foreach (['autoload.php', 'composer/autoload_real.php', 'composer/installed.php', 'composer/installed.json'] as $required) {
            if (!is_file($vendor . '/' . $required)) {
                throw new GuardianException('Staged vendor is invalid: ' . $required . ' is missing.');
            }
        }
        $installed = $this->installedPackageNames($vendor);
        if ($installed === []) {
            throw new GuardianException('Staged vendor is invalid: no installed packages could be read.');
        }
        // TYPO3 core must be present.
        if (!\in_array('typo3/cms-core', $installed, true)) {
            throw new GuardianException('Staged vendor is invalid: typo3/cms-core is missing.');
        }
        // Guardian's own dependency (symfony/process) must be present.
        if (!\in_array('symfony/process', $installed, true)) {
            $log('warning', 'Staged vendor does not list symfony/process — Guardian may not function after the switch.');
        }
        // The installed set must match composer.lock.
        $lockNames = $this->lockPackageNames();
        $missing = array_values(array_diff($lockNames, $installed));
        if ($missing !== []) {
            throw new GuardianException('Staged vendor does not match composer.lock (missing: ' . implode(', ', \array_slice($missing, 0, 5)) . ').');
        }
        $symlinks = $this->reconcileAndValidateSymlinks($vendor, $log);
        $log('info', sprintf('Staged vendor validated: %d packages, autoload present, matches composer.lock, %d symlinks reconciled (all within the project).', \count($installed), $symlinks));
    }

    /**
     * Atomically switches the staged vendor into place, retaining the previous
     * vendor at {@see oldVendorPath()}. Verifies the live autoload afterwards.
     *
     * @param callable(string $level, string $line): void $log
     * @return string the retained old-vendor path (for rollback / cleanup)
     * @throws GuardianException
     */
    public function switchIntoPlace(string $jobId, callable $log): string
    {
        $live = $this->liveVendorPath();
        $staged = $this->stagedVendorPath($jobId);
        $old = $this->oldVendorPath($jobId);

        if (!$this->canAtomicallySwitch($jobId)) {
            throw new GuardianException('Atomic vendor switch is not possible (staging is on a different filesystem). Recovery blocked.');
        }

        $log('info', 'Atomically switching the validated staged vendor into place…');
        $this->switch->switchIn($live, $staged, $old);

        if (!is_file($live . '/autoload.php')) {
            $log('error', 'Live autoload missing after switch — reverting immediately.');
            $this->switch->revert($live, $old, $this->failedPath($jobId));
            throw new GuardianException('Vendor switch verification failed — the previous vendor was restored.');
        }
        $log('info', 'Vendor switch complete; previous vendor retained at ' . basename($old) . ' until final success.');

        return $old;
    }

    /**
     * Reverts a completed switch during rollback: moves the live (broken) vendor
     * aside and restores the retained previous vendor.
     *
     * @param callable(string $level, string $line): void $log
     */
    public function rollbackSwitch(string $jobId, callable $log): void
    {
        $log('info', 'Rolling back the vendor switch — restoring the previous vendor.');
        $this->switch->revert($this->liveVendorPath(), $this->oldVendorPath($jobId), $this->failedPath($jobId));
    }

    /**
     * Removes retained old-vendor + build directories after the whole recovery
     * has succeeded. Never called on failure (data is kept for diagnosis).
     */
    public function cleanupAfterSuccess(string $jobId): void
    {
        $this->switch->discard($this->oldVendorPath($jobId));
        $this->switch->discard($this->buildDir($jobId));
    }

    /**
     * Prepares an isolated build directory: symlinks the project's other files
     * (so path repositories and local packages resolve) and copies the freshly
     * restored composer.json + composer.lock in.
     *
     * @param callable(string $level, string $line): void $log
     */
    private function prepareBuildDir(string $jobId, callable $log): string
    {
        $project = $this->projectPath();
        $build = $this->buildDir($jobId);
        $this->switch->discard($build);
        if (!@mkdir($build, 0o750, true) && !is_dir($build)) {
            throw new GuardianException('Could not create the vendor build directory.');
        }

        $skip = ['.', '..', 'vendor', self::BUILD_DIR, 'composer.json', 'composer.lock'];
        foreach (scandir($project) ?: [] as $entry) {
            if (\in_array($entry, $skip, true) || str_starts_with($entry, self::OLD_PREFIX) || str_starts_with($entry, self::FAILED_PREFIX)) {
                continue;
            }
            @symlink($project . '/' . $entry, $build . '/' . $entry);
        }
        // Copy the restored composer files (never symlink — they are the source of truth).
        foreach (['composer.json', 'composer.lock'] as $file) {
            if (!@copy($project . '/' . $file, $build . '/' . $file)) {
                throw new GuardianException('Could not stage ' . $file . ' for the vendor rebuild.');
            }
        }
        $log('info', 'Prepared isolated build directory for composer install.');

        return $build;
    }

    private function assertComposerFilesValid(): void
    {
        foreach (['composer.json', 'composer.lock'] as $name) {
            $path = $this->projectPath() . '/' . $name;
            if (!is_file($path)) {
                throw new GuardianException('Cannot rebuild vendor: ' . $name . ' is missing.');
            }
            if (!\is_array(json_decode((string) @file_get_contents($path), true))) {
                throw new GuardianException('Cannot rebuild vendor: ' . $name . ' is not valid JSON.');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function installedPackageNames(string $vendor): array
    {
        $data = json_decode((string) @file_get_contents($vendor . '/composer/installed.json'), true);
        $packages = \is_array($data) ? ($data['packages'] ?? $data) : [];
        $names = [];
        foreach ((array) $packages as $pkg) {
            if (\is_array($pkg) && isset($pkg['name'])) {
                $names[] = (string) $pkg['name'];
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function lockPackageNames(): array
    {
        $data = json_decode((string) @file_get_contents($this->projectPath() . '/composer.lock'), true);
        $names = [];
        foreach ((array) ($data['packages'] ?? []) as $pkg) {
            if (\is_array($pkg) && isset($pkg['name'])) {
                $names[] = (string) $pkg['name'];
            }
        }

        return $names;
    }

    /**
     * Validates the symlinks in a staged vendor tree WITHOUT following them and
     * WITHOUT depending on realpath() (which would tunnel through the staging
     * build-directory symlinks and mis-judge containment).
     *
     * The trust boundary is the PROJECT ROOT, not the vendor subtree. Composer
     * legitimately creates two kinds of relative symlinks that must be allowed:
     *   - bin proxies:  vendor/bin/typo3 -> ../typo3/cms-cli/typo3   (inside vendor)
     *   - path repos:   vendor/acme/ext  -> ../../packages/ext        (inside the
     *                   project but OUTSIDE vendor — normal for local TYPO3 extensions)
     * Both resolve inside the project and are safe. Only a symlink whose target,
     * evaluated at its FINAL live location (<project>/vendor/…) and normalised
     * lexically, ESCAPES the project root is an "arbitrary external symlink" and
     * is rejected. Containment is judged with the pure {@see PathNormalizer}, so
     * a symlink can never tunnel a "contained" path out through another symlink.
     *
     * @return int the number of symlinks validated
     * @throws GuardianException with a precise, administrator-facing report
     */
    /**
     * Reconciles and validates every symlink in the staged vendor tree.
     *
     * A Composer path-repository symlink is written with a target relative to the
     * directory it was created in. Because vendor is built/extracted in a deeper
     * STAGING directory than its final `<project>/vendor` location, a raw target
     * like `../../../../packages/x` (correct from staging) would overshoot the
     * project root once switched in — leaving a broken, out-of-project link. So
     * instead of trusting the raw target, this method resolves each link's REAL
     * in-project source and REBUILDS the target so it is correct at the final
     * live location. Security is unchanged: a link whose real source is outside
     * the project root is still rejected as an external symlink, and links that
     * stay inside the vendor tree (bin proxies) are left untouched.
     *
     * @param callable(string $level, string $line): void $log
     * @return int number of symlinks reconciled/validated
     * @throws GuardianException
     */
    private function reconcileAndValidateSymlinks(string $vendor, callable $log): int
    {
        $stagedVendor = rtrim($vendor, '/');
        $stagedVendorReal = realpath($stagedVendor);
        $stagedVendorReal = $stagedVendorReal !== false ? $stagedVendorReal : $stagedVendor;
        $projectReal = realpath($this->projectPath());
        $projectReal = $projectReal !== false ? $projectReal : $this->projectPath();
        $liveVendor = $projectReal . '/vendor';

        // Collect the symlinks first — never mutate the tree while iterating it.
        $links = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stagedVendor, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                $links[] = $item->getPathname();
            }
        }

        $count = 0;
        foreach ($links as $linkPath) {
            $rawTarget = @readlink($linkPath);
            if ($rawTarget === false || $rawTarget === '') {
                continue;
            }
            ++$count;

            $relInVendor = ltrim(substr($linkPath, \strlen($stagedVendor)), '/');
            $finalLinkDir = \dirname($liveVendor . '/' . $relInVendor);
            $realTarget = realpath($linkPath); // resolved at the (valid) staging location

            if ($realTarget !== false) {
                // Within the vendor tree (bin proxies): the relative target is
                // depth-invariant under the switch — leave it untouched.
                if ($realTarget === $stagedVendorReal || str_starts_with($realTarget . '/', $stagedVendorReal . '/')) {
                    continue;
                }
                // Inside the project but outside vendor (path repository): rebuild
                // the target so it is correct from the FINAL live vendor location.
                if ($realTarget === $projectReal || str_starts_with($realTarget . '/', $projectReal . '/')) {
                    $newTarget = $this->relativePath($finalLinkDir, $realTarget);
                    if ($newTarget !== $rawTarget) {
                        if (!@unlink($linkPath) || !@symlink($newTarget, $linkPath)) {
                            throw new GuardianException('Could not reconstruct the path-repository symlink vendor/' . $relInVendor . '.');
                        }
                        $log('info', 'Reconstructed path-repository symlink: vendor/' . $relInVendor . ' -> ' . $newTarget . ' (was ' . $rawTarget . ').');
                    }
                    continue;
                }
                // Real source outside the project → genuine external symlink.
                throw new GuardianException($this->rejectionReport(
                    'vendor/' . $relInVendor,
                    $rawTarget,
                    $realTarget,
                    'Resolved target is outside the project root — rejected as an external symlink.',
                ));
            }

            // Dangling link (target missing): judge lexically at the final location.
            $candidate = str_starts_with($rawTarget, '/') ? $rawTarget : $finalLinkDir . '/' . $rawTarget;
            $normalized = $this->pathNormalizer->normalize($candidate);
            if ($this->pathNormalizer->isContained($projectReal, $normalized)) {
                continue;
            }
            throw new GuardianException($this->rejectionReport(
                'vendor/' . $relInVendor,
                $rawTarget,
                $normalized,
                'Escapes the project root — rejected as an external symlink.',
            ));
        }

        return $count;
    }

    /**
     * Pure lexical relative path from one absolute directory to an absolute
     * target, using forward slashes (POSIX). Both inputs must be absolute.
     */
    private function relativePath(string $fromDir, string $to): string
    {
        $from = explode('/', trim($fromDir, '/'));
        $dest = explode('/', trim($to, '/'));
        $i = 0;
        $max = min(\count($from), \count($dest));
        while ($i < $max && $from[$i] === $dest[$i]) {
            ++$i;
        }
        $up = \count($from) - $i;
        $down = \array_slice($dest, $i);
        $rel = str_repeat('../', $up) . implode('/', $down);

        return $rel === '' ? '.' : rtrim($rel, '/');
    }

    /**
     * Builds a precise, non-sensitive report for a rejected symlink so an
     * authenticated administrator sees exactly what was rejected and why.
     */
    private function rejectionReport(string $relPath, string $rawTarget, string $normalized, string $reason): string
    {
        $project = $this->projectPath();
        $shown = $this->pathNormalizer->isContained($project, $normalized)
            ? ltrim(substr($normalized, \strlen($project)), '/')
            : $normalized;

        return "Staged vendor contains an unsafe symlink — rejected.\n"
            . "Rejected symlink:\n" . $relPath . ' -> ' . $rawTarget . "\n"
            . "Normalized target:\n" . $shown . "\n"
            . "Result:\n" . $reason;
    }

    private function failedPath(string $jobId): string
    {
        return $this->projectPath() . '/' . self::FAILED_PREFIX . $this->safeId($jobId);
    }

    private function safeId(string $jobId): string
    {
        if (preg_match('#^[A-Za-z0-9._-]{1,64}$#', $jobId) !== 1) {
            throw new GuardianException('Invalid recovery job id for vendor staging.');
        }

        return $jobId;
    }

    private function projectPath(): string
    {
        return rtrim($this->environment->projectPath(), '/');
    }
}
