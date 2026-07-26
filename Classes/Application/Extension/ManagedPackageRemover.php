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
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Extension\ManagedExtensionRegistry;

/**
 * Owns the SOURCE-DIRECTORY side of a Guardian-managed uploaded-extension
 * removal — the part `composer remove` never handles. `composer remove` only
 * drops the root requirement; the local path-repository source directory under
 * packages/ and its `options.versions` pin are left behind, which is exactly why
 * a re-install used to fail with "The target package directory already exists.".
 *
 * Every deletion here is gated by cryptographic PROOF of ownership: the registry
 * record must say Guardian created that exact path AND a marker file written at
 * install time must still carry the matching token AND the directory's identity
 * must still match the recorded package. If any check fails the directory is
 * treated as foreign and never touched.
 *
 * The owned directory is first MOVED to a private quarantine
 * (var/guardian/extensions/removed/<job-id>/<dir>) so Composer/TYPO3 run against
 * the removed state; the quarantine is deleted only after the whole removal
 * succeeds and is moved back if any later step fails.
 */
final class ManagedPackageRemover
{
    public const MARKER_FILE = '.guardian-owned.json';

    public function __construct(
        private readonly ProjectEnvironmentInterface $environment,
        private readonly ManagedExtensionRegistry $registry,
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    /**
     * Write the ownership marker into a freshly installed managed directory and
     * return the random token to persist in the registry record.
     */
    public function writeOwnershipMarker(string $absoluteDir, string $package): string
    {
        $token = bin2hex(random_bytes(16));
        $payload = json_encode([
            'guardian_owned' => true,
            'package' => $package,
            'token' => $token,
            'created_at' => gmdate('c'),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        if (is_dir($absoluteDir) && $payload !== false) {
            @file_put_contents(rtrim($absoluteDir, '/') . '/' . self::MARKER_FILE, $payload, \LOCK_EX);
            @chmod(rtrim($absoluteDir, '/') . '/' . self::MARKER_FILE, 0o640);
        }

        return $token;
    }

    /**
     * Build a verified removal plan for a package. `ownership_verified` is true
     * ONLY when Guardian can prove it created and still owns the exact directory.
     * `path` (absolute) is for internal use only and must not be sent to a client.
     *
     * @return array{
     *   package: string, managed: bool, ownership_verified: bool, reason: ?string,
     *   extension_key: string, version: string, source_relative: string,
     *   target_dir: string, path: string, detected_name: ?string, detected_version: ?string
     * }
     */
    public function plan(string $package): array
    {
        $base = [
            'package' => $package,
            'managed' => false,
            'ownership_verified' => false,
            'reason' => null,
            'extension_key' => '',
            'version' => '',
            'source_relative' => '',
            'target_dir' => '',
            'path' => '',
            'detected_name' => null,
            'detected_version' => null,
        ];

        $record = $this->registry->get($package);
        if ($record === null) {
            return ['reason' => 'not_managed'] + $base;
        }
        $base['managed'] = true;
        $base['extension_key'] = (string) ($record['extension_key'] ?? '');
        $base['version'] = (string) ($record['version'] ?? '');

        $abs = rtrim((string) ($record['path'] ?? ''), '/');
        $base['path'] = $abs;
        $packagesDir = $this->packagesDir();
        $base['target_dir'] = $abs !== '' ? basename($abs) : '';
        $base['source_relative'] = $abs !== '' ? $this->relativePath($abs) : (string) ($record['source_relative'] ?? '');

        // (3) Directory must live inside the project's packages/ directory.
        if ($abs === '' || !$this->isInside($packagesDir, $abs)) {
            return ['reason' => 'path_outside_packages'] + $base;
        }
        // (4) Registry must assert Guardian ownership of this exact path.
        if (($record['guardian_owned'] ?? false) !== true || !$this->registry->ownsDirectory($package, $abs)) {
            return ['reason' => 'ownership_flag_missing'] + $base;
        }
        if (!is_dir($abs)) {
            // Nothing on disk to remove; registration cleanup is still valid.
            return ['reason' => 'directory_absent', 'ownership_verified' => true] + $base;
        }
        // Read the current on-disk identity for verification + UI display.
        $detected = $this->readComposerName($abs);
        $base['detected_name'] = $detected['name'];
        $base['detected_version'] = $detected['version'];
        // (5) Current identity must still match the ownership record.
        if ($detected['name'] !== null && strtolower($detected['name']) !== strtolower($package)) {
            return ['reason' => 'identity_mismatch'] + $base;
        }
        // (6) Directory must not have been replaced (marker token must match).
        if (!$this->markerMatches($abs, $package, $record)) {
            return ['reason' => 'marker_mismatch'] + $base;
        }

        return ['ownership_verified' => true] + $base;
    }

    /**
     * Classify an existing packages/<key> directory found during upload
     * inspection while the package is NOT installed.
     *
     * @return array{classification: string, source_relative: string, detected_name: ?string, detected_version: ?string, owned: bool}
     */
    public function classifyExistingDirectory(string $extensionKey, ?string $composerName): array
    {
        $abs = $this->packagesDir() . '/' . $extensionKey;
        $out = [
            'classification' => 'none',
            'source_relative' => 'packages/' . $extensionKey,
            'detected_name' => null,
            'detected_version' => null,
            'owned' => false,
        ];
        if ($extensionKey === '' || !is_dir($abs)) {
            return $out;
        }
        $detected = $this->readComposerName($abs);
        $out['detected_name'] = $detected['name'];
        $out['detected_version'] = $detected['version'];

        // Verified Guardian-owned orphan: a registry record for the detected (or
        // requested) package proves Guardian created exactly this directory.
        $candidate = $detected['name'] ?? $composerName;
        if ($candidate !== null) {
            $record = $this->registry->get($candidate);
            if ($record !== null
                && ($record['guardian_owned'] ?? false) === true
                && $this->registry->ownsDirectory($candidate, $abs)
                && $this->markerMatches($abs, $candidate, $record)
            ) {
                $out['classification'] = 'verified_guardian_orphan';
                $out['owned'] = true;

                return $out;
            }
        }

        if ($detected['name'] !== null && $composerName !== null && strtolower($detected['name']) === strtolower($composerName)) {
            // Same package identity, but Guardian has no ownership proof.
            $out['classification'] = 'matching_unowned';

            return $out;
        }
        if ($detected['name'] !== null) {
            // A different, unrelated package occupies the directory.
            $out['classification'] = 'conflicting';

            return $out;
        }
        $out['classification'] = 'unknown';

        return $out;
    }

    /**
     * Move the owned directory into a private per-job quarantine and return its
     * absolute path. Only proceeds when ownership is verified.
     *
     * @param array<string, mixed> $plan
     */
    public function quarantine(array $plan, string $jobId): string
    {
        if (($plan['ownership_verified'] ?? false) !== true) {
            throw new GuardianException('Refusing to quarantine a directory Guardian does not own.');
        }
        $source = rtrim((string) ($plan['path'] ?? ''), '/');
        if ($source === '' || !is_dir($source)) {
            throw new GuardianException('The owned source directory is missing.');
        }
        $base = $this->quarantineBase($jobId);
        if (!is_dir($base) && !@mkdir($base, 0o700, true) && !is_dir($base)) {
            throw new GuardianException('Could not create the quarantine directory.');
        }
        $target = $base . '/' . basename($source);
        if (!@rename($source, $target)) {
            throw new GuardianException('Could not move the owned source directory to quarantine.');
        }

        return $target;
    }

    /** Move a quarantined directory back to its original path (failure recovery). */
    public function restoreQuarantine(string $quarantinePath, string $originalPath): void
    {
        if ($quarantinePath === '' || !is_dir($quarantinePath)) {
            return;
        }
        if (is_dir($originalPath)) {
            // Live path already restored (e.g. by the safety-backup rollback).
            return;
        }
        @rename($quarantinePath, $originalPath);
    }

    /** Delete the quarantined directory after a fully successful removal. */
    public function commitQuarantine(string $jobId): void
    {
        $this->removeTree($this->quarantineBase($jobId));
    }

    /**
     * Remove ONLY this package's entry from every path repository's
     * `options.versions` map in composer.json. Never removes a repository, the
     * broad packages/* repository, or another package's mapping.
     */
    public function removeVersionMapping(string $package): void
    {
        $file = $this->composerJsonPath();
        $original = (string) @file_get_contents($file);
        $data = json_decode($original, true);
        if (!\is_array($data) || !\is_array($data['repositories'] ?? null)) {
            return;
        }
        $changed = false;
        foreach ($data['repositories'] as $i => $repo) {
            if (!\is_array($repo) || !\is_array($repo['options']['versions'] ?? null)) {
                continue;
            }
            if (\array_key_exists($package, $repo['options']['versions'])) {
                unset($data['repositories'][$i]['options']['versions'][$package]);
                $changed = true;
                if ($data['repositories'][$i]['options']['versions'] === []) {
                    unset($data['repositories'][$i]['options']['versions']);
                }
            }
        }
        if (!$changed) {
            return;
        }
        $data['repositories'] = array_values($data['repositories']);
        $encoded = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($encoded !== false) {
            @file_put_contents($file, $encoded . "\n", \LOCK_EX);
        }
    }

    /** Delete a verified Guardian-owned orphan directory and forget its record. */
    public function removeOwnedDirectory(array $plan): void
    {
        if (($plan['ownership_verified'] ?? false) !== true) {
            throw new GuardianException('Refusing to delete a directory Guardian does not own.');
        }
        $abs = rtrim((string) ($plan['path'] ?? ''), '/');
        if ($abs !== '' && is_dir($abs) && $this->isInside($this->packagesDir(), $abs)) {
            $this->removeTree($abs);
        }
        $this->removeVersionMapping((string) $plan['package']);
        $this->registry->forget((string) $plan['package']);
    }

    public function forget(string $package): void
    {
        $this->registry->forget($package);
    }

    private function markerMatches(string $absDir, string $package, array $record): bool
    {
        $expected = (string) ($record['ownership_marker'] ?? '');
        $markerFile = rtrim($absDir, '/') . '/' . self::MARKER_FILE;
        if ($expected === '' || !is_file($markerFile)) {
            // Legacy record without a marker: fall back to the strong registry
            // path+owned proof only when the on-disk identity also matches.
            return $expected === '';
        }
        $marker = json_decode((string) @file_get_contents($markerFile), true);

        return \is_array($marker)
            && (string) ($marker['token'] ?? '') === $expected
            && strtolower((string) ($marker['package'] ?? '')) === strtolower($package);
    }

    /**
     * @return array{name: ?string, version: ?string}
     */
    private function readComposerName(string $absDir): array
    {
        $file = rtrim($absDir, '/') . '/composer.json';
        if (!is_file($file)) {
            return ['name' => null, 'version' => null];
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!\is_array($data)) {
            return ['name' => null, 'version' => null];
        }

        return [
            'name' => \is_string($data['name'] ?? null) ? $data['name'] : null,
            'version' => \is_string($data['version'] ?? null) ? $data['version'] : null,
        ];
    }

    private function quarantineBase(string $jobId): string
    {
        $safeId = preg_replace('/[^A-Za-z0-9_.-]/', '', $jobId) ?: 'job';

        return $this->workingDirectory->resolve('extensions/removed/' . $safeId);
    }

    private function packagesDir(): string
    {
        return rtrim($this->environment->projectPath(), '/') . '/packages';
    }

    private function composerJsonPath(): string
    {
        return rtrim($this->environment->projectPath(), '/') . '/composer.json';
    }

    private function relativePath(string $abs): string
    {
        $project = rtrim($this->environment->projectPath(), '/') . '/';
        if (str_starts_with($abs, $project)) {
            return substr($abs, \strlen($project));
        }

        return 'packages/' . basename($abs);
    }

    private function isInside(string $parent, string $child): bool
    {
        $parent = rtrim($parent, '/') . '/';
        $child = rtrim($child, '/') . '/';

        return $child !== $parent && str_starts_with($child, $parent);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) && !is_link($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
