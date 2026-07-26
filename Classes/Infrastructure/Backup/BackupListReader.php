<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Backup;

use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;

/**
 * Read-only listing of existing backups under <var>/guardian/backup.
 *
 * Ports the listing half of the audited Contao BackupManager (creation and
 * deletion are destructive and belong to a later phase). Until the backup
 * feature is built, this simply returns an empty list, which is exactly what a
 * fresh install shows. It never writes anything.
 */
final class BackupListReader
{
    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    /**
     * @return list<array{name: string, created_at: string, total_size: string, path: string}>
     */
    public function list(): array
    {
        $base = $this->workingDirectory->resolve('backup');
        if (!is_dir($base)) {
            return [];
        }

        $dirs = glob($base . '/*', \GLOB_ONLYDIR);
        if ($dirs === false) {
            return [];
        }

        $backups = [];
        foreach ($dirs as $dir) {
            $manifest = [];
            $manifestFile = $dir . '/manifest.json';
            if (is_file($manifestFile)) {
                $decoded = json_decode((string) @file_get_contents($manifestFile), true);
                if (\is_array($decoded)) {
                    $manifest = $decoded;
                }
            }

            $backups[] = [
                'name' => basename($dir),
                'created_at' => (string) ($manifest['created_at'] ?? basename($dir)),
                'total_size' => (string) ($manifest['total_size'] ?? '?'),
                'path' => $dir,
            ];
        }

        usort($backups, static fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        return $backups;
    }

    public function count(): int
    {
        return \count($this->list());
    }
}
