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
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Backup\BackupComponent;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Recovery\VendorRestoreStrategy;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\ZipBackupArchiveExtractor;

/**
 * Mandatory, read-only recovery dry run. It validates the archive, verifies the
 * Composer files, checks disk space and atomic-switch capability, and computes a
 * plan — WITHOUT touching the live project, maintenance mode or the database.
 *
 * On success it persists a fingerprint of (backup, selected components, vendor
 * strategy). A real recovery is refused unless the current request's fingerprint
 * matches the last successful dry run — and the fingerprint is invalidated the
 * moment any selection changes.
 */
final class RecoveryDryRun
{
    private const FINGERPRINT_FILE = 'recovery/dry-run.json';

    public function __construct(
        private readonly BackupCatalog $catalog,
        private readonly ZipBackupArchiveExtractor $extractor,
        private readonly VendorRecoveryService $vendor,
        private readonly ProjectEnvironmentInterface $environment,
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    /**
     * @param array<string, mixed> $components
     * @return array{ok: bool, fingerprint: string, checks: list<array{key:string,severity:string,message:string}>, plan: array<string,mixed>}
     */
    public function run(string $backupId, array $components, string $vendorStrategyRaw): array
    {
        $strategy = VendorRestoreStrategy::fromString($vendorStrategyRaw);
        $checks = [];

        $manifest = $this->catalog->assertRecoverable($backupId); // checksum + containment + completed
        $checks[] = $this->check('backup_valid', 'ok', 'Backup verified (checksum matches, archive contained).');

        $selection = RecoveryComponentSelection::fromRequest($components, $manifest);

        // Archive readability + safe entries (no absolute paths, .., null bytes).
        if (!ZipBackupArchiveExtractor::isSupported()) {
            $checks[] = $this->check('archive_tool', 'error', 'The PHP zip extension (ZipArchive) is required.');
        } else {
            $this->extractor->open($this->catalog->archivePathFor($backupId));
            try {
                $this->extractor->assertSafeEntries();
                $hasComposerJson = $this->extractor->hasEntry('composer.json');
                $hasComposerLock = $this->extractor->hasEntry('composer.lock');
                $checks[] = ($hasComposerJson && $hasComposerLock)
                    ? $this->check('composer_files', 'ok', 'Archive contains composer.json and composer.lock.')
                    : $this->check('composer_files', $strategy->touchesVendor() ? 'error' : 'warning', 'Archive is missing composer.json/composer.lock.');
            } catch (GuardianException $e) {
                $checks[] = $this->check('archive_entries', 'error', $e->getMessage());
            } finally {
                $this->extractor->close();
            }
        }

        // Atomic-switch capability — any vendor uncertainty is a BLOCKING error.
        if ($strategy->touchesVendor()) {
            $checks[] = $this->vendor->canAtomicallySwitch('dry-run-probe')
                ? $this->check('atomic_switch', 'ok', 'An atomic vendor switch is possible on this filesystem.')
                : $this->check('atomic_switch', 'error', 'Atomic vendor switch is NOT possible (different filesystems) — vendor recovery is blocked.');
        }

        // Disk space: archive + snapshot + staged vendor + retained old vendor.
        $checks[] = $this->diskCheck($manifest->archiveSize());

        $ok = true;
        foreach ($checks as $c) {
            if ($c['severity'] === 'error') {
                $ok = false;
            }
        }

        $fingerprint = self::fingerprint($backupId, $components, $strategy->value);
        if ($ok) {
            $this->store($fingerprint);
        }

        return [
            'ok' => $ok,
            'fingerprint' => $fingerprint,
            'checks' => $checks,
            'plan' => [
                'components' => array_map(static fn (BackupComponent $c): string => $c->value, $selection->selected()),
                'vendor_strategy' => $strategy->value,
                'composer_will_run' => $strategy === VendorRestoreStrategy::Rebuild,
                'archive_size' => $manifest->archiveSize(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $components
     */
    public static function fingerprint(string $backupId, array $components, string $vendorStrategy): string
    {
        $selected = [];
        foreach ($components as $key => $value) {
            if ($value === true) {
                $selected[] = (string) $key;
            }
        }
        sort($selected);

        return hash('sha256', json_encode([$backupId, $selected, $vendorStrategy], \JSON_UNESCAPED_SLASHES) ?: '');
    }

    public function matchesLastDryRun(string $fingerprint): bool
    {
        $data = $this->read();

        return $data !== null && hash_equals((string) ($data['fingerprint'] ?? ''), $fingerprint);
    }

    public function invalidate(): void
    {
        $file = $this->workingDirectory->resolve(self::FINGERPRINT_FILE);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function diskCheck(int $archiveSize): array
    {
        $free = @disk_free_space(rtrim($this->environment->projectPath(), '/'));
        if ($free === false) {
            return $this->check('disk_space', 'warning', 'Could not determine free disk space.');
        }
        // archive + safety snapshot + staged vendor + retained old vendor ≈ 4×.
        $required = max(1, $archiveSize) * 4;

        return $free >= $required
            ? $this->check('disk_space', 'ok', 'Enough free disk space for staging + retained vendor.')
            : $this->check('disk_space', 'error', 'Insufficient disk space for safe staged recovery (need ~' . $this->human($required) . ').');
    }

    private function store(string $fingerprint): void
    {
        $file = $this->workingDirectory->resolve(self::FINGERPRINT_FILE);
        $dir = \dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o750, true);
        }
        @file_put_contents($file, json_encode(['fingerprint' => $fingerprint, 'at' => gmdate('c')], \JSON_PRETTY_PRINT), \LOCK_EX);
        @chmod($file, 0o640);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(): ?array
    {
        $file = $this->workingDirectory->resolve(self::FINGERPRINT_FILE);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return \is_array($data) ? $data : null;
    }

    /**
     * @return array{key:string,severity:string,message:string}
     */
    private function check(string $key, string $severity, string $message): array
    {
        return ['key' => $key, 'severity' => $severity, 'message' => $message];
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $v = (float) $bytes;
        $i = 0;
        while ($v >= 1024 && $i < \count($units) - 1) {
            $v /= 1024;
            $i++;
        }

        return round($v, 1) . ' ' . $units[$i];
    }
}
