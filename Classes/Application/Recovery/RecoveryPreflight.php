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

use Vtinnovations\GuardianTypo3\Application\Contract\DatabaseImporterInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Domain\Backup\BackupComponent;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Backup\ComponentPathMap;
use Vtinnovations\GuardianTypo3\Infrastructure\Maintenance\FileMaintenanceMode;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\ZipBackupArchiveExtractor;

/**
 * Mandatory, strictly READ-ONLY preflight for a recovery. It verifies the backup,
 * compares source vs current environment, checks writability, disk space,
 * database connectivity/driver and maintenance capability, and separates results
 * into safe checks, warnings and blocking errors. Recovery cannot start while a
 * blocking error exists. It never modifies files or the database.
 */
final class RecoveryPreflight
{
    public function __construct(
        private readonly BackupCatalog $catalog,
        private readonly ComponentPathMap $paths,
        private readonly ProjectEnvironmentInterface $environment,
        private readonly DatabaseImporterInterface $databaseImporter,
        private readonly FileMaintenanceMode $maintenance,
    ) {
    }

    /**
     * @param array<string, mixed> $requestComponents
     * @return array<string, mixed>
     */
    public function run(string $backupId, array $requestComponents): array
    {
        $checks = [];

        try {
            $manifest = $this->catalog->assertRecoverable($backupId);
        } catch (GuardianException $e) {
            return [
                'backupId' => $backupId,
                'meta' => ['backupId' => $backupId],
                'components' => ['available' => [], 'selected' => [], 'missing' => []],
                'checks' => [$this->check('backup_valid', 'error', $e->getMessage())],
                'canRecover' => false,
            ];
        }

        $selection = RecoveryComponentSelection::fromRequest($requestComponents, $manifest);
        $available = array_map(static fn (BackupComponent $c): string => $c->value, $manifest->availableComponents());
        $selected = array_map(static fn (BackupComponent $c): string => $c->value, $selection->selected());
        $missing = array_map(static fn (BackupComponent $c): string => $c->value, $selection->missing());

        $checks[] = $this->check('backup_valid', 'ok', 'Backup verified (status completed).');
        $checks[] = $this->check('checksum', 'ok', 'Checksum matches.');
        $checks[] = ZipBackupArchiveExtractor::isSupported()
            ? $this->check('archive_tool', 'ok', 'ZipArchive is available.')
            : $this->check('archive_tool', 'error', 'The PHP zip extension (ZipArchive) is required.');

        if ($selected === []) {
            $checks[] = $this->check('selection', 'error', 'Select at least one component present in the backup.');
        } elseif ($missing !== []) {
            $checks[] = $this->check('selection', 'warning', 'Some requested components are not in this backup: ' . implode(', ', $missing) . '.');
        } else {
            $checks[] = $this->check('selection', 'ok', \count($selected) . ' component(s) selected.');
        }

        $checks[] = $this->diskSpaceCheck($manifest);
        $checks = array_merge($checks, $this->writabilityChecks($selection));

        if ($selection->contains(BackupComponent::Database)) {
            $checks[] = $this->databaseImporter->isSupported()
                ? $this->check('db_driver', 'ok', 'Database driver "' . $this->databaseImporter->driver() . '" is supported.')
                : $this->check('db_driver', 'error', 'Database restore supports MySQL/MariaDB only (driver "' . ($this->databaseImporter->driver() ?: 'unknown') . '").');
            $checks[] = $this->databaseImporter->canConnect()
                ? $this->check('db_connectivity', 'ok', 'Database connection succeeded.')
                : $this->check('db_connectivity', 'error', 'Could not connect to the database.');
        }

        $checks[] = $this->maintenance->isCapable()
            ? $this->check('maintenance', 'ok', 'Maintenance mode can be toggled.')
            : $this->check('maintenance', 'warning', 'Maintenance mode may not be togglable (working directory not writable).');

        $checks[] = $this->maintenance->isEnabled()
            ? $this->check('active_recovery', 'warning', 'Maintenance mode is already active — another recovery may be running.')
            : $this->check('active_recovery', 'ok', 'No recovery currently in progress.');

        $checks[] = $this->versionCheck($manifest);

        $hasError = false;
        foreach ($checks as $check) {
            if ($check['severity'] === 'error') {
                $hasError = true;
            }
        }

        return [
            'backupId' => $backupId,
            'meta' => [
                'backupId' => $backupId,
                'backupDate' => $manifest->createdAt(),
                'backupType' => $manifest->type(),
                'archiveSize' => $manifest->archiveSize(),
                'archiveSizeHuman' => $this->humanBytes($manifest->archiveSize()),
                'checksumStatus' => 'verified',
                'sourceTypo3' => $manifest->typo3Version(),
                'currentTypo3' => $this->environment->typo3Version(),
                'sourcePhp' => $manifest->phpVersion(),
                'currentPhp' => $this->environment->phpVersion(),
                'sourceHost' => $manifest->hostname(),
                'databaseSize' => $manifest->databaseSize(),
            ],
            'components' => ['available' => $available, 'selected' => $selected, 'missing' => $missing],
            'checks' => $checks,
            'canRecover' => !$hasError && $selected !== [],
        ];
    }

    /**
     * @return array{key: string, severity: string, message: string}
     */
    private function diskSpaceCheck(BackupManifest $manifest): array
    {
        $free = @disk_free_space($this->paths->projectPath());
        if ($free === false) {
            return $this->check('disk_space', 'warning', 'Could not determine free disk space.');
        }
        $required = max(1, $manifest->archiveSize()) * 2;
        if ($free < $manifest->archiveSize()) {
            return $this->check('disk_space', 'error', sprintf('Only %s free — the archive alone needs %s.', $this->humanBytes((int) $free), $this->humanBytes($manifest->archiveSize())));
        }
        if ($free < $required) {
            return $this->check('disk_space', 'warning', sprintf('%s free; roughly %s recommended (archive + temporary extraction).', $this->humanBytes((int) $free), $this->humanBytes($required)));
        }

        return $this->check('disk_space', 'ok', sprintf('%s free disk space.', $this->humanBytes((int) $free)));
    }

    /**
     * @return list<array{key: string, severity: string, message: string}>
     */
    private function writabilityChecks(RecoveryComponentSelection $selection): array
    {
        $checks = [];
        foreach ($selection->selected() as $component) {
            if ($component->isDatabase()) {
                continue;
            }
            $target = $this->paths->target($component);
            if ($target === null) {
                continue;
            }
            $probe = is_dir($target) ? $target : \dirname($target);
            $checks[] = is_writable($probe)
                ? $this->check('writable_' . $component->value, 'ok', $component->value . ' target is writable.')
                : $this->check('writable_' . $component->value, 'error', $component->value . ' target is not writable.');
        }

        return $checks;
    }

    /**
     * @return array{key: string, severity: string, message: string}
     */
    private function versionCheck(BackupManifest $manifest): array
    {
        $source = $manifest->typo3Version();
        $current = $this->environment->typo3Version();
        if ($source === '' || $current === '') {
            return $this->check('version', 'warning', 'Could not compare TYPO3 versions.');
        }
        [$sMajor, $sMinor] = $this->majorMinor($source);
        [$cMajor, $cMinor] = $this->majorMinor($current);
        if ($sMajor !== $cMajor) {
            return $this->check('version', 'warning', sprintf('Backup is TYPO3 %s but this installation is %s — a major version difference may cause schema incompatibilities.', $source, $current));
        }
        if ($sMinor !== $cMinor) {
            return $this->check('version', 'warning', sprintf('Backup is TYPO3 %s, this installation is %s (minor difference).', $source, $current));
        }

        return $this->check('version', 'ok', 'TYPO3 version matches the backup.');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function majorMinor(string $version): array
    {
        $parts = explode('.', $version);

        return [$parts[0] ?? '0', $parts[1] ?? '0'];
    }

    /**
     * @return array{key: string, severity: string, message: string}
     */
    private function check(string $key, string $severity, string $message): array
    {
        return ['key' => $key, 'severity' => $severity, 'message' => $message];
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $i = 0;
        while ($value >= 1024 && $i < \count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, 1) . ' ' . $units[$i];
    }
}
