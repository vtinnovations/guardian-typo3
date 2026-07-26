<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Extension;

use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;

/**
 * Records Guardian-managed ownership of custom uploaded extension packages
 * (var/guardian/extensions/owned.json) so Guardian knows which directories under
 * packages/ it created and may later offer to delete — and, crucially, which it
 * did NOT create and must never delete merely because a name matches.
 *
 * Each record is keyed by the Composer package identity and carries the source
 * archive checksum, install timestamp, installed path, version, the acting
 * administrator, the ownership flag and the safety-backup id.
 */
final class ManagedExtensionRegistry
{
    private const FILE = 'extensions/owned.json';

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    /**
     * @param array{package: string, extension_key?: string, version?: string, path: string, source_relative?: string, checksum: string, admin?: string, guardian_owned?: bool, ownership_marker?: string, safety_backup?: ?string} $record
     */
    public function record(array $record): void
    {
        $all = $this->all();
        $record['installed_at'] = gmdate('c');
        $record['guardian_owned'] = $record['guardian_owned'] ?? true;
        $all[(string) $record['package']] = $record;
        $this->write($all);
    }

    public function forget(string $package): void
    {
        $all = $this->all();
        if (isset($all[$package])) {
            unset($all[$package]);
            $this->write($all);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $package): ?array
    {
        $all = $this->all();

        return isset($all[$package]) && \is_array($all[$package]) ? $all[$package] : null;
    }

    /**
     * True only when Guardian created the installed directory for this package
     * AND that recorded path matches the one being considered for deletion.
     */
    public function ownsDirectory(string $package, string $absolutePath): bool
    {
        $record = $this->get($package);
        if ($record === null || ($record['guardian_owned'] ?? false) !== true) {
            return false;
        }
        $recorded = rtrim((string) ($record['path'] ?? ''), '/');

        return $recorded !== '' && $recorded === rtrim($absolutePath, '/');
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $file = $this->workingDirectory->resolve(self::FILE);
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $all
     */
    private function write(array $all): void
    {
        $file = $this->workingDirectory->resolve(self::FILE);
        $dir = \dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o750, true);
        }
        @file_put_contents($file, json_encode($all, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), \LOCK_EX);
        @chmod($file, 0o640);
    }
}
