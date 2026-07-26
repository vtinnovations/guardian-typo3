<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Extension;

use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Application\Update\UpdateService;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Job\Job;
use Vtinnovations\GuardianTypo3\Infrastructure\Extension\ManagedExtensionRegistry;
use Vtinnovations\GuardianTypo3\Infrastructure\Upload\UploadStagingArea;

/**
 * Orchestrates the custom-extension flow after a ZIP is staged: inspect →
 * (optionally generate a safe JSON Composer wrapper for legacy extensions) →
 * compute a dry-run fingerprint → run the Composer dry run → install through the
 * shared Guardian pipeline. The heavy lifting (backup, maintenance, Composer,
 * schema, cache, verify, rollback) is the SAME {@see UpdateService} pipeline
 * used everywhere else; this service only prepares safe, validated inputs.
 *
 * The fingerprint binds a confirmed install to the exact bytes that were
 * analysed: if the staged tree changes between dry run and install, the
 * fingerprint no longer matches and the install is refused.
 */
final class CustomExtensionInstallService
{
    public function __construct(
        private readonly UploadStagingArea $staging,
        private readonly StagedExtensionInspector $inspector,
        private readonly UpdateService $updateService,
        private readonly ManagedExtensionRegistry $registry,
        private readonly ManagedPackageRemover $remover,
        private readonly ProjectEnvironmentInterface $environment,
    ) {
    }

    /**
     * Inspect the staged upload (generating a legacy wrapper when possible) and
     * return the inspection, a stable fingerprint and the local-package plan.
     *
     * @return array{token: string, checksum: string, fingerprint: string, inspection: array<string, mixed>, local_package: ?array<string, mixed>}
     * @throws GuardianException
     */
    public function describe(string $token): array
    {
        $staged = $this->staging->get($token);
        $filename = (string) ($staged['filename'] ?? '');
        $inspection = $this->inspector->inspect($staged['extracted'], $filename);

        // For a legacy extension with a derivable identity, generate a fixed
        // JSON Composer wrapper into the private staging dir, then re-inspect so
        // the result reflects a Composer-ready package.
        if (($inspection['legacy'] ?? false) === true && ($inspection['wrapper_generatable'] ?? false) === true) {
            $this->generateWrapper($staged['extracted'], $inspection);
            $inspection = $this->inspector->inspect($staged['extracted'], $filename);
        }

        $root = $this->extensionRoot($staged['extracted'], $inspection);

        // Pin the resolved stable version INTO the extension's own composer.json
        // (in the private staging area — never the live tree). Composer path
        // repositories otherwise default an unversioned package to an unstable
        // "dev-main". Writing the version here means EVERY repository that later
        // serves the package resolves it as that exact stable version — including
        // a pre-existing canonical "packages/*" repository that will cover
        // packages/<key> after install. This is done BEFORE the fingerprint so the
        // dry-run and the real install analyse byte-identical staged content.
        if (\is_string($inspection['version'] ?? null) && $inspection['version'] !== '') {
            $this->ensureComposerVersion($root, $inspection['version']);
        }

        $fingerprint = $this->fingerprint($root, $staged['checksum']);

        $localPackage = null;
        if (($inspection['installable'] ?? false) === true && \is_string($inspection['composer_name'] ?? null) && \is_string($inspection['target_dir'] ?? null)) {
            $localPackage = [
                'staging_path' => $root,
                'target_dir' => $inspection['target_dir'],
                'composer_name' => $inspection['composer_name'],
                'extension_key' => (string) $inspection['extension_key'],
                'version' => (string) ($inspection['version'] ?? ''),
                'checksum' => $staged['checksum'],
                'fingerprint' => $fingerprint,
            ];
        }

        // Classify any packages/<key> directory that already exists while the
        // package is NOT installed (an orphan left by a prior removal). The UI
        // uses this to offer reuse/remove instead of failing with a bare
        // "target directory already exists".
        $existingDirectory = null;
        if (($inspection['already_installed'] ?? false) !== true && \is_string($inspection['extension_key'] ?? null) && $inspection['extension_key'] !== '') {
            $classified = $this->remover->classifyExistingDirectory((string) $inspection['extension_key'], $inspection['composer_name'] ?? null);
            if ($classified['classification'] !== 'none') {
                $existingDirectory = $classified;
            }
        }

        return [
            'token' => $token,
            'checksum' => $staged['checksum'],
            'fingerprint' => $fingerprint,
            'inspection' => $inspection,
            'local_package' => $localPackage,
            'existing_directory' => $existingDirectory,
        ];
    }

    /**
     * Remove a verified Guardian-owned ORPHAN directory (one left by a prior
     * removal) so the same extension can be re-uploaded. Deletes only when
     * ownership is proven; never touches an unowned or conflicting directory.
     *
     * @return array{removed: bool, classification: string}
     * @throws GuardianException
     */
    public function removeOrphanedDirectory(string $extensionKey): array
    {
        $classified = $this->remover->classifyExistingDirectory($extensionKey, null);
        if ($classified['classification'] !== 'verified_guardian_orphan') {
            throw new GuardianException('orphan_not_owned');
        }
        $package = (string) ($classified['detected_name'] ?? '');
        if ($package === '') {
            throw new GuardianException('orphan_not_owned');
        }
        $plan = $this->remover->plan($package);
        if (($plan['ownership_verified'] ?? false) !== true) {
            throw new GuardianException('orphan_not_owned');
        }
        $this->remover->removeOwnedDirectory($plan);

        return ['removed' => true, 'classification' => $classified['classification']];
    }

    /**
     * @throws GuardianException
     */
    public function startDryRun(string $token, string $fingerprint): Job
    {
        $plan = $this->requireInstallablePlan($token, $fingerprint);

        return $this->updateService->startLocalInstallDryRun($plan);
    }

    /**
     * @throws GuardianException
     */
    public function startInstall(string $token, string $fingerprint, bool $snapshotVendor, string $admin): Job
    {
        $plan = $this->requireInstallablePlan($token, $fingerprint);
        $this->assertTargetSafe($plan);

        return $this->updateService->startLocalInstall($plan, $snapshotVendor, $admin);
    }

    /**
     * @return array<string, mixed>
     * @throws GuardianException
     */
    private function requireInstallablePlan(string $token, string $fingerprint): array
    {
        $described = $this->describe($token);
        if ($described['local_package'] === null) {
            throw new GuardianException('extension_not_installable');
        }
        if (!hash_equals((string) $described['fingerprint'], $fingerprint)) {
            // The staged files changed since the dry run was analysed.
            throw new GuardianException('fingerprint_mismatch');
        }

        return $described['local_package'];
    }

    /**
     * Refuse to overwrite an existing packages/ directory unless Guardian proves
     * it owns exactly that path (managed ownership) — never delete unknown code.
     *
     * @param array<string, mixed> $plan
     */
    private function assertTargetSafe(array $plan): void
    {
        $target = rtrim($this->projectPackagesDir(), '/') . '/' . (string) $plan['target_dir'];
        if (is_dir($target) && !$this->registry->ownsDirectory((string) $plan['composer_name'], $target)) {
            throw new GuardianException('target_exists_unmanaged');
        }
    }

    private function projectPackagesDir(): string
    {
        return rtrim($this->environment->projectPath(), '/') . '/packages';
    }

    /**
     * Generate a minimal, fixed-template composer.json (JSON only — never PHP)
     * for a legacy extension so it becomes Composer-installable.
     *
     * @param array<string, mixed> $inspection
     */
    private function generateWrapper(string $extractedDir, array $inspection): void
    {
        $root = $this->extensionRoot($extractedDir, $inspection);
        $file = $root . '/composer.json';
        if (is_file($file)) {
            return;
        }
        $key = (string) $inspection['extension_key'];
        $studly = str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
        $typo3 = \is_string($inspection['typo3_constraint'] ?? null) && $inspection['typo3_constraint'] !== ''
            ? $inspection['typo3_constraint']
            : '^13.4 || ^14.0';

        $composer = [
            'name' => 'local/' . $key,
            'type' => 'typo3-cms-extension',
            'description' => 'Locally installed TYPO3 extension ' . $key . ' (Guardian-generated wrapper).',
            'require' => ['typo3/cms-core' => $typo3],
            'autoload' => ['psr-4' => ['LocalExtensions\\' . $studly . '\\' => 'Classes/']],
            'extra' => ['typo3/cms' => ['extension-key' => $key]],
        ];
        // Pin the resolved stable version so the path package is never treated as
        // an unstable dev-main branch during resolution.
        if (\is_string($inspection['version'] ?? null) && $inspection['version'] !== '') {
            $composer['version'] = $inspection['version'];
        }
        @file_put_contents($file, json_encode($composer, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n", \LOCK_EX);
    }

    /**
     * Ensure the staged extension's composer.json carries an explicit stable
     * version, injecting the inspected version when the package ships none (as
     * friendsoftypo3/content-blocks does — its version lives in git tags). This is
     * the single source of truth that makes a Composer path repository resolve the
     * package as e.g. 2.4.8 instead of "dev-main". Idempotent: a version the
     * package already declares is respected and left untouched. JSON only — no PHP
     * is ever generated or executed.
     */
    private function ensureComposerVersion(string $root, string $version): void
    {
        $file = $root . '/composer.json';
        if (!is_file($file)) {
            return;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!\is_array($data)) {
            // Malformed composer.json — inspection has already flagged it; never
            // rewrite an unparseable file here.
            return;
        }
        $existing = $data['version'] ?? null;
        if (\is_string($existing) && ltrim(trim($existing), 'vV') !== '') {
            return;
        }
        $data['version'] = $version;
        $encoded = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($encoded !== false) {
            @file_put_contents($file, $encoded . "\n", \LOCK_EX);
        }
    }

    /**
     * @param array<string, mixed> $inspection
     */
    private function extensionRoot(string $extractedDir, array $inspection): string
    {
        $relative = $inspection['root_relative'] ?? '';

        return $relative === '' || !\is_string($relative) ? rtrim($extractedDir, '/') : rtrim($extractedDir, '/') . '/' . $relative;
    }

    /**
     * A stable content fingerprint of the extension root: the archive checksum
     * plus a sorted "relpath:size" manifest. Any change to the staged bytes
     * changes the fingerprint and invalidates a pending confirmation.
     */
    private function fingerprint(string $root, string $archiveChecksum): string
    {
        $manifest = [];
        if (is_dir($root)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                /** @var \SplFileInfo $item */
                $rel = ltrim(substr($item->getPathname(), \strlen($root)), '/');
                $manifest[] = $rel . ':' . ($item->isFile() ? $item->getSize() : 'd');
            }
        }
        sort($manifest);

        return hash('sha256', $archiveChecksum . "\n" . implode("\n", $manifest));
    }
}
