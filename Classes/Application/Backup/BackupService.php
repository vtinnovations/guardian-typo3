<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Backup;

use Vtinnovations\GuardianTypo3\Application\Contract\DatabaseDumperInterface;
use Vtinnovations\GuardianTypo3\Application\Environment\CapabilityAssertion;
use Vtinnovations\GuardianTypo3\Application\Contract\LockFactoryInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\SystemLoggerInterface;
use Vtinnovations\GuardianTypo3\Domain\Backup\BackupComponent;
use Vtinnovations\GuardianTypo3\Domain\Backup\BackupStatus;
use Vtinnovations\GuardianTypo3\Domain\Backup\BackupType;
use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Backup\BackupLog;
use Vtinnovations\GuardianTypo3\Infrastructure\Backup\BackupStorage;
use Vtinnovations\GuardianTypo3\Infrastructure\Backup\FileCollector;
use Vtinnovations\GuardianTypo3\Infrastructure\Backup\ZipBackupArchiveWriter;
use Vtinnovations\GuardianTypo3\Infrastructure\Composer\PathRepositoryInspector;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\ExtensionInformation;

/**
 * Orchestrates a single, synchronous backup run — faithfully preserving the
 * original Contao Guardian interaction flow, where a manual backup runs to
 * completion within the request and returns its manifest + log (the UI shows a
 * spinner and waits). No fragile background daemon is invented; a global lock
 * prevents overlapping runs.
 *
 * Pipeline: validate → lock → prepare dir → dump database → stream files into
 * the archive → embed manifest → close → checksum → atomically finalise →
 * persist manifest + log → apply retention → release lock. A failure at any
 * point cleans up temporary files and leaves NO completed-looking archive; it
 * throws instead of reporting success.
 */
final class BackupService
{
    private const LOCK_NAME = 'backup';

    public function __construct(
        private readonly BackupStorage $storage,
        private readonly ZipBackupArchiveWriter $archiveWriter,
        private readonly FileCollector $fileCollector,
        private readonly DatabaseDumperInterface $databaseDumper,
        private readonly LockFactoryInterface $lockFactory,
        private readonly ClockInterface $clock,
        private readonly ProjectEnvironmentInterface $environment,
        private readonly ExtensionInformation $extensionInformation,
        private readonly RetentionPolicy $retentionPolicy,
        private readonly SystemLoggerInterface $systemLogger,
        private readonly PathRepositoryInspector $pathRepositoryInspector,
        private readonly CapabilityAssertion $capability,
    ) {
    }

    /**
     * @throws GuardianException on any failure (never returns without a completed archive)
     */
    public function create(ComponentSelection $selection, BackupType $type, int $retention): BackupResult
    {
        // Enforced here rather than only at the caller, so a console command, a
        // scheduler task or another service cannot reach the archive writer
        // without the same entitlement a backend request needs.
        $this->capability->requireLicensed('Creating a backup');

        if (!ZipBackupArchiveWriter::isSupported()) {
            throw new GuardianException('Backups require the PHP "zip" extension (ZipArchive), which is not available.');
        }

        $this->storage->ensureBaseDir();

        $lock = $this->lockFactory->create(self::LOCK_NAME);
        if (!$lock->acquire()) {
            throw new GuardianException('Another backup is currently running. Please wait for it to finish.');
        }

        $now = $this->clock->now();
        $id = $this->storage->generateId($now);
        $log = new BackupLog();
        $logger = static function (string $line) use ($log): void {
            $log->add($line);
        };

        $tempArchive = $this->storage->tempArchivePath($id);
        $tempSql = $this->storage->tempSqlPath($id);
        $finalArchive = $this->storage->archivePath($id);

        $databaseResult = null;
        $fileCount = 0;

        try {
            $logger(sprintf('Starting %s backup %s.', $type->value, $id));

            // 1. Database dump (streamed to a temp file), when selected.
            if ($selection->isSelected(BackupComponent::Database)) {
                $databaseResult = $this->databaseDumper->dumpTo($tempSql, $logger);
            } else {
                $logger('Database component not selected — skipping database dump.');
            }

            // 2. Archive — stream every file, never load the tree into memory.
            $this->archiveWriter->open($tempArchive);
            try {
                if ($databaseResult !== null && is_file($tempSql)) {
                    $this->archiveWriter->addFile($tempSql, 'database.sql');
                    $logger('Added database.sql to the archive.');
                }
                foreach ($this->fileCollector->collect($selection, $logger) as $entry) {
                    $this->archiveWriter->addFile($entry['abs'], $entry['entry']);
                    $fileCount++;
                }
                $logger(sprintf('Collected %d file(s).', $fileCount));

                // 3. Embed a manifest inside the archive too (self-describing).
                $embedded = $this->buildManifest($id, $type, $selection, $now, null, $databaseResult, $fileCount, 0, '');
                $this->archiveWriter->addString('manifest.json', $this->encode($embedded));

                $this->archiveWriter->close();
            } catch (\Throwable $e) {
                $this->archiveWriter->abort();
                throw $e;
            }

            // 4. Remove the temporary SQL dump before finalising the archive.
            if (is_file($tempSql)) {
                @unlink($tempSql);
            }

            // 5. Atomic finalisation: rename temp → final only after success.
            if (!@rename($tempArchive, $finalArchive)) {
                throw new GuardianException('Could not finalise the backup archive.');
            }
            @chmod($finalArchive, $this->storage->fileMode());

            // 6. Checksum + size AFTER completion.
            $archiveSize = (int) @filesize($finalArchive);
            $checksum = hash_file('sha256', $finalArchive);
            if ($checksum === false) {
                throw new GuardianException('Could not compute the backup checksum.');
            }
            $logger(sprintf('Archive finalised: %s (%s), sha256 %s.', basename($finalArchive), $this->humanBytes($archiveSize), substr($checksum, 0, 16) . '…'));

            // 7. Persist manifest sidecar + log.
            $completedAt = $this->clock->now();
            $manifest = $this->buildManifest($id, $type, $selection, $now, $completedAt, $databaseResult, $fileCount, $archiveSize, $checksum);
            $this->storage->writeManifest($id, $manifest);
            $log->writeTo($this->storage->logPath($id), $this->storage->fileMode());

            // 8. Retention.
            $pruned = $this->applyRetention($type, $retention, $id, $logger);

            $this->systemLogger->info(sprintf('Backup %s created (%s, %s).', $id, $type->value, $this->humanBytes($archiveSize)), 'backup');

            return new BackupResult($manifest, $log->lines(), $pruned);
        } catch (\Throwable $e) {
            // Failure cleanup — never leave a completed-looking archive.
            $this->archiveWriter->abort();
            if (is_file($tempSql)) {
                @unlink($tempSql);
            }
            if (is_file($tempArchive)) {
                @unlink($tempArchive);
            }
            $this->systemLogger->error(sprintf('Backup %s failed: %s', $id, $e->getMessage()), 'backup');

            if ($e instanceof GuardianException) {
                throw $e;
            }
            throw new GuardianException('Backup failed: ' . $e->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * @param callable(string):void $logger
     */
    private function applyRetention(BackupType $type, int $retention, string $currentId, callable $logger): int
    {
        $ids = $this->retentionPolicy->idsToPrune($this->storage->list(), $type->value, $retention);
        $pruned = 0;
        foreach ($ids as $id) {
            if ($id === $currentId) {
                continue;
            }
            if ($this->storage->delete($id)) {
                $pruned++;
            }
        }
        if ($pruned > 0) {
            $logger(sprintf('Retention: deleted %d old %s backup(s).', $pruned, $type->value));
        }

        return $pruned;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildManifest(
        string $id,
        BackupType $type,
        ComponentSelection $selection,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $completedAt,
        ?DatabaseDumpResult $database,
        int $fileCount,
        int $archiveSize,
        string $checksum,
    ): array {
        return [
            'id' => $id,
            'filename' => $id . '.zip',
            'status' => ($completedAt !== null ? BackupStatus::Completed : BackupStatus::Running)->value,
            'type' => $type->value,
            'created_at' => $createdAt->format(\DATE_ATOM),
            'completed_at' => $completedAt?->format(\DATE_ATOM),
            'components' => $selection->toArray(),
            'file_count' => $fileCount,
            'archive_size' => $archiveSize,
            'archive_size_human' => $this->humanBytes($archiveSize),
            'database_size' => $database?->bytes ?? 0,
            'database_method' => $database?->method ?? null,
            'database_server' => $database?->serverVersion ?? null,
            'typo3_version' => $this->environment->typo3Version(),
            'php_version' => $this->environment->phpVersion(),
            'guardian_version' => $this->extensionInformation->version(),
            'hostname' => $this->hostname(),
            'checksum' => $checksum,
            'checksum_algo' => 'sha256',
            'path_repositories' => $this->pathRepositories($selection),
        ];
    }

    /**
     * Authoritative record of Composer path-repository packages (local packages
     * symlinked into vendor/). Recovery uses this — plus composer.json path
     * repositories — as the source of truth for reconstructing correct vendor
     * symlink targets, instead of trusting a depth-relative archived link.
     *
     * @return list<array{package: string, vendor_link: string, source_path: string, original_target: ?string, source_included: bool}>
     */
    private function pathRepositories(ComponentSelection $selection): array
    {
        $project = rtrim($this->environment->projectPath(), '/');
        $vendor = $project . '/vendor';
        $sel = $selection->toArray();
        $vendorSelected = ($sel['vendor'] ?? false) === true;
        $packagesSelected = ($sel['packages'] ?? false) === true;

        $out = [];
        foreach ($this->pathRepositoryInspector->inspect($vendor, $vendor, $project) as $repo) {
            // The package content is captured when the followed vendor symlink is
            // backed up, or when the component holding its source is selected.
            $sourceComponentSelected = $packagesSelected && str_starts_with($repo['source_path'], 'packages/');
            $out[] = [
                'package' => $repo['package'],
                'vendor_link' => $repo['vendor_link'],
                'source_path' => $repo['source_path'],
                'original_target' => $repo['original_target'],
                'source_included' => $repo['inside_project'] && ($vendorSelected || $sourceComponentSelected),
            ];
        }

        return $out;
    }

    private function hostname(): string
    {
        $host = gethostname();

        return $host !== false ? $host : 'unknown';
    }

    private function encode(array $data): string
    {
        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
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
