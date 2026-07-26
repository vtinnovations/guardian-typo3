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

use Vtinnovations\GuardianTypo3\Application\Backup\BackupService;
use Vtinnovations\GuardianTypo3\Application\Backup\ComponentSelection;
use Vtinnovations\GuardianTypo3\Application\Contract\DatabaseImporterInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\LockFactoryInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\MaintenanceModeInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\RecoveryHistoryStoreInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\SystemLoggerInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Backup\BackupComponent;
use Vtinnovations\GuardianTypo3\Domain\Backup\BackupType;
use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Filesystem\PathNormalizer;
use Vtinnovations\GuardianTypo3\Domain\Process\CommandRequest;
use Vtinnovations\GuardianTypo3\Domain\Recovery\VendorRestoreStrategy;
use Vtinnovations\GuardianTypo3\Infrastructure\Backup\BackupStorage;
use Vtinnovations\GuardianTypo3\Infrastructure\Backup\ComponentPathMap;
use Vtinnovations\GuardianTypo3\Infrastructure\Process\SymfonyProcessCommandExecutor;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\RecoveryTransactionJournal;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\ZipBackupArchiveExtractor;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\ComposerEnvironment;

/**
 * Restores a verified Guardian backup — hardened after a production incident in
 * which an in-place `vendor/` overwrite destroyed a live installation.
 *
 * Guarantees now enforced:
 *   - `vendor/` is NEVER wiped or overwritten in place. It is re-established
 *     through {@see VendorRecoveryService} (rebuild-from-lock by default, or a
 *     strictly-gated archived restore), always staged, validated and switched in
 *     atomically, with the previous vendor retained for rollback.
 *   - A persistent {@see RecoveryTransactionJournal} is written before any
 *     destructive step; an interrupted recovery is detected and blocks a new one.
 *   - A mandatory safety snapshot precedes changes; maintenance mode is kept ON
 *     during rollback; success is only reported after a real TYPO3 bootstrap
 *     verification. It never reports success without a verified restore.
 */
final class RestoreService
{
    private const LOCK_NAME = 'recovery';

    /** Non-vendor restore order; vendor is handled separately and safely. */
    private const ORDER = [
        BackupComponent::ComposerJson,
        BackupComponent::ComposerLock,
        BackupComponent::Database,
        BackupComponent::Configuration,
        BackupComponent::Packages,
        BackupComponent::Templates,
        BackupComponent::Fileadmin,
        BackupComponent::PublicAssets,
    ];

    public function __construct(
        private readonly BackupCatalog $catalog,
        private readonly ZipBackupArchiveExtractor $extractor,
        private readonly ComponentPathMap $paths,
        private readonly DatabaseImporterInterface $databaseImporter,
        private readonly BackupService $backupService,
        private readonly MaintenanceModeInterface $maintenance,
        private readonly LockFactoryInterface $lockFactory,
        private readonly ClockInterface $clock,
        private readonly SystemLoggerInterface $systemLogger,
        private readonly RecoveryHistoryStoreInterface $history,
        private readonly BackupStorage $storage,
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
        private readonly PathNormalizer $pathNormalizer,
        private readonly RecoveryEmailNotifier $emailNotifier,
        private readonly VendorRecoveryService $vendor,
        private readonly RecoveryTransactionJournal $journal,
        private readonly SymfonyProcessCommandExecutor $executor,
        private readonly ComposerEnvironment $composerEnvironment,
    ) {
    }

    /**
     * @param array<string, mixed> $requestComponents
     * @throws GuardianException
     */
    public function restore(
        string $backupId,
        array $requestComponents,
        bool $createSnapshot,
        bool $confirm,
        string $vendorStrategy = 'rebuild',
    ): RecoveryResult {
        if (!$confirm) {
            throw new GuardianException('Recovery must be explicitly confirmed.');
        }
        if (!$createSnapshot) {
            throw new GuardianException('A pre-recovery safety snapshot is mandatory and cannot be skipped.');
        }
        if (!ZipBackupArchiveExtractor::isSupported()) {
            throw new GuardianException('Recovery requires the PHP "zip" extension (ZipArchive).');
        }

        // Refuse to start over an interrupted recovery — it must be rolled back first.
        if ($this->journal->findIncomplete() !== []) {
            throw new GuardianException('An interrupted recovery transaction exists. Roll it back before starting a new recovery.');
        }

        $manifest = $this->catalog->assertRecoverable($backupId);
        $selection = RecoveryComponentSelection::fromRequest($requestComponents, $manifest);
        $strategy = VendorRestoreStrategy::fromString($vendorStrategy);

        if ($selection->isEmpty() && !$strategy->touchesVendor()) {
            throw new GuardianException('Select at least one component present in the backup.');
        }
        if ($selection->contains(BackupComponent::Database)) {
            if (!$this->databaseImporter->isSupported()) {
                throw new GuardianException('Database restore supports MySQL/MariaDB only.');
            }
            if (!$this->databaseImporter->canConnect()) {
                throw new GuardianException('Cannot connect to the database — aborting before any change.');
            }
        }

        $jobId = $this->generateJobId();
        $this->assertVendorStrategyFeasible($strategy, $manifest, $jobId);

        $lock = $this->lockFactory->create(self::LOCK_NAME);
        if (!$lock->acquire()) {
            throw new GuardianException('Another recovery is currently running. Please wait for it to finish.');
        }

        $log = [];
        $logger = function (string $line) use (&$log): void {
            $log[] = '[Guardian Recovery] ' . gmdate('H:i:s') . ' ' . $line;
        };
        $vlog = static function (string $level, string $line) use ($logger): void {
            $logger(($level === 'info' ? '' : strtoupper($level) . ': ') . $line);
        };

        $restored = [];
        $snapshotId = null;
        $maintenanceOn = false;
        $previousMaintenance = false;
        $vendorSwitched = false;

        $this->journal->begin($jobId, [
            'backup_id' => $backupId,
            'components' => array_map(static fn (BackupComponent $c): string => $c->value, $selection->selected()),
            'vendor_strategy' => $strategy->value,
        ]);

        try {
            $logger(sprintf('Starting recovery %s from backup %s (vendor: %s).', $jobId, $backupId, $strategy->value));
            $this->emailNotifier->sendPreRecovery($backupId, $logger);
            $this->emailNotifier->sendEvent('recovery_started', ['job_id' => $jobId, 'backup_id' => $backupId], $logger);

            $previousMaintenance = $this->maintenance->isEnabled();
            try {
                $this->maintenance->enable();
                $maintenanceOn = true;
                $this->journal->update($jobId, ['maintenance_previous' => $previousMaintenance]);
                $logger('Maintenance mode enabled (previous state: ' . ($previousMaintenance ? 'on' : 'off') . ').');
            } catch (\Throwable $e) {
                $logger('Could not enable maintenance mode: ' . $e->getMessage());
            }

            $this->journal->step($jobId, 'safety_snapshot');
            $snapshot = $this->backupService->create($this->snapshotSelection($selection), BackupType::Manual, 30);
            $snapshotId = $snapshot->id();
            $this->journal->update($jobId, ['safety_snapshot_id' => $snapshotId]);
            $logger('Safety snapshot created: ' . $snapshotId . ' (vendor preserved via atomic rename, not archived).');

            // ── non-vendor components (single files + directories), staged-safe DB ──
            $this->journal->step($jobId, 'restore_components');
            $this->extractor->open($this->storage->archivePath($backupId));
            try {
                $this->extractor->assertSafeEntries();
                $restored = $this->restoreComponents($selection, $logger);
            } finally {
                $this->extractor->close();
            }
            if ($this->databaseWasRestored($selection, $restored)) {
                $this->journal->update($jobId, ['database_restored' => true]);
            }

            // ── vendor: hardened, staged, atomic ──
            if ($strategy->touchesVendor()) {
                $vendorSwitched = $this->restoreVendor($strategy, $backupId, $jobId, $vlog);
                if ($vendorSwitched) {
                    $restored[] = 'vendor';
                    $this->journal->update($jobId, [
                        'old_vendor_path' => basename($this->vendor->oldVendorPath($jobId)),
                        'new_vendor_path' => 'vendor',
                    ]);
                }
            }

            // ── mandatory post-recovery verification ──
            $this->journal->step($jobId, 'verify');
            $this->verifyBootstrap($strategy->touchesVendor(), $vlog);

            if ($maintenanceOn) {
                $this->restoreMaintenance($previousMaintenance, $logger);
            }

            $this->journal->update($jobId, ['state' => RecoveryTransactionJournal::STATE_COMPLETED, 'step' => 'completed', 'rollback_state' => 'none']);
            if ($vendorSwitched) {
                $this->vendor->cleanupAfterSuccess($jobId); // discard retained old-vendor + build only on success
            }

            $this->recordHistory($backupId, $restored, $snapshotId, 'success', 'Recovery completed and verified.', $log);
            $this->systemLogger->info(sprintf('[Guardian Recovery] %s from %s completed (%s).', $jobId, $backupId, implode(', ', $restored)), 'recovery');
            $this->emailNotifier->sendEvent('recovery_completed', ['job_id' => $jobId, 'backup_id' => $backupId, 'result' => 'success'], $logger);

            return new RecoveryResult($backupId, $restored, $snapshotId, $log, false);
        } catch (\Throwable $e) {
            $logger('Recovery failed: ' . $e->getMessage());
            $this->emailNotifier->sendEvent('recovery_failed', ['job_id' => $jobId, 'backup_id' => $backupId, 'reason' => $e->getMessage()], $logger);
            $this->emailNotifier->sendEvent('rollback_started', ['job_id' => $jobId, 'backup_id' => $backupId], $logger);
            $rolledBack = $this->rollback($jobId, $vendorSwitched, $snapshotId, $selection, $logger);
            $this->emailNotifier->sendEvent($rolledBack ? 'rollback_completed' : 'rollback_failed', ['job_id' => $jobId, 'backup_id' => $backupId, 'rollback_result' => $rolledBack ? 'succeeded' : 'failed'], $logger);

            if ($rolledBack) {
                $this->restoreMaintenance($previousMaintenance, $logger);
                $this->journal->update($jobId, ['state' => RecoveryTransactionJournal::STATE_ROLLED_BACK, 'rollback_state' => 'succeeded']);
            } else {
                $logger('Automatic rollback failed or was incomplete. Maintenance mode is left ON — inspect var/guardian/recovery/' . $jobId . '/ before bringing the site online.');
                $this->journal->update($jobId, ['state' => RecoveryTransactionJournal::STATE_ROLLBACK_FAILED, 'rollback_state' => 'failed']);
            }

            $this->recordHistory($backupId, $restored, $snapshotId, $rolledBack ? 'rolled_back' : 'failed', $e->getMessage(), $log);
            $this->systemLogger->error(sprintf('[Guardian Recovery] %s from %s failed: %s', $jobId, $backupId, $e->getMessage()), 'recovery');

            $message = $e instanceof GuardianException ? $e->getMessage() : 'Recovery failed: ' . $e->getMessage();
            throw new GuardianException($message . ($rolledBack ? ' (rolled back — previous state restored).' : ' (ROLLBACK INCOMPLETE — manual intervention required).'));
        } finally {
            $lock->release();
        }
    }

    /**
     * Rolls back an interrupted or failed recovery journal without starting a new
     * one. Restores the previous vendor (if switched) and the safety snapshot.
     *
     * @throws GuardianException
     */
    public function rollbackInterrupted(string $jobId): RecoveryResult
    {
        $data = $this->journal->get($jobId);
        if ($data === null) {
            throw new GuardianException('No recovery transaction found for that id.');
        }
        $log = [];
        $logger = function (string $line) use (&$log): void {
            $log[] = '[Guardian Recovery] ' . gmdate('H:i:s') . ' ' . $line;
        };
        $vendorSwitched = ($data['new_vendor_path'] ?? null) === 'vendor';
        $snapshotId = \is_string($data['safety_snapshot_id'] ?? null) ? $data['safety_snapshot_id'] : null;
        $selection = RecoveryComponentSelection::fromRequest([], $this->catalog->find((string) ($data['backup_id'] ?? '')) ?? throw new GuardianException('Backup for the interrupted recovery is missing.'));
        $backupId = (string) ($data['backup_id'] ?? '');

        $this->emailNotifier->sendEvent('interrupted_recovery_detected', ['job_id' => $jobId, 'backup_id' => $backupId], $logger);
        $this->emailNotifier->sendEvent('rollback_started', ['job_id' => $jobId, 'backup_id' => $backupId], $logger);
        $rolledBack = $this->rollback($jobId, $vendorSwitched, $snapshotId, $selection, $logger);
        $this->emailNotifier->sendEvent($rolledBack ? 'rollback_completed' : 'rollback_failed', ['job_id' => $jobId, 'backup_id' => $backupId, 'rollback_result' => $rolledBack ? 'succeeded' : 'failed'], $logger);
        $previousMaintenance = (bool) ($data['maintenance_previous'] ?? false);
        if ($rolledBack) {
            $this->restoreMaintenance($previousMaintenance, $logger);
            $this->journal->update($jobId, ['state' => RecoveryTransactionJournal::STATE_ROLLED_BACK, 'rollback_state' => 'succeeded']);
        } else {
            $this->journal->update($jobId, ['state' => RecoveryTransactionJournal::STATE_ROLLBACK_FAILED, 'rollback_state' => 'failed']);
        }

        return new RecoveryResult((string) ($data['backup_id'] ?? ''), [], $snapshotId, $log, $rolledBack);
    }

    /**
     * @param callable(string $level, string $line): void $vlog
     * @return bool whether the vendor was switched (so rollback knows to revert)
     */
    private function restoreVendor(VendorRestoreStrategy $strategy, string $backupId, string $jobId, callable $vlog): bool
    {
        $this->journal->step($jobId, 'vendor_' . $strategy->value);
        if ($strategy === VendorRestoreStrategy::Rebuild) {
            $vlog('info', 'Vendor strategy: REBUILD from composer.lock (safe default).');
            $this->vendor->rebuild($jobId, $vlog);
            $this->vendor->switchIntoPlace($jobId, $vlog);

            return true;
        }

        // Archived (advanced): extract the vendor tree into staging, validate, switch.
        $vlog('warning', 'Vendor strategy: ARCHIVED restore (advanced) — staging and validating before any switch.');
        $staged = $this->vendor->stagedVendorPath($jobId);
        $buildDir = \dirname($staged);
        if (!is_dir($buildDir) && !@mkdir($buildDir, 0o750, true) && !is_dir($buildDir)) {
            throw new GuardianException('Could not create the vendor staging directory.');
        }
        $this->extractor->open($this->storage->archivePath($backupId));
        try {
            $this->extractor->assertSafeEntries();
            $entries = $this->extractor->entriesUnderPrefix('vendor');
            if ($entries === []) {
                throw new GuardianException('The backup does not contain a vendor archive.');
            }
            $this->extractor->extractEntries($entries, $buildDir);
        } finally {
            $this->extractor->close();
        }
        $this->vendor->validateStagedVendor($jobId, $vlog);
        $this->vendor->switchIntoPlace($jobId, $vlog);

        return true;
    }

    /**
     * @param callable(string $level, string $line): void $vlog
     * @throws GuardianException on verification failure
     */
    private function verifyBootstrap(bool $vendorTouched, callable $vlog): void
    {
        if (!$vendorTouched) {
            $vlog('info', 'Vendor was not changed; skipping vendor bootstrap verification.');

            return;
        }
        $php = $this->composerEnvironment->phpBinary();
        if ($php === null) {
            throw new GuardianException('Cannot verify the restored installation: no PHP CLI binary is available.');
        }
        $project = rtrim($this->paths->projectPath(), '/');

        // 1) autoload loads in a SEPARATE PHP process.
        $autoload = $this->executor->run(CommandRequest::create(
            [$php, '-r', 'require $argv[1]; echo "GUARDIAN_OK";', $project . '/vendor/autoload.php'],
            $project,
            60,
        ));
        if (!$autoload->isSuccessful() || !str_contains($autoload->stdout, 'GUARDIAN_OK')) {
            throw new GuardianException('Post-recovery verification failed: vendor/autoload.php did not load in a fresh PHP process.');
        }
        $vlog('info', 'Verified: vendor/autoload.php loads in a separate PHP process.');

        // 2) TYPO3 CLI can bootstrap (13.4 + 14 compatible: `typo3 --version`).
        $console = $this->composerEnvironment->typo3Console();
        if ($console !== null) {
            $boot = $this->executor->run(CommandRequest::create([$php, $console, '--version'], $project, 90));
            if (!$boot->isSuccessful()) {
                throw new GuardianException('Post-recovery verification failed: TYPO3 CLI could not bootstrap.');
            }
            $vlog('info', 'Verified: TYPO3 CLI bootstraps (' . trim($boot->stdout) . ').');
        } else {
            $vlog('warning', 'vendor/bin/typo3 not found — skipped the TYPO3 CLI bootstrap check.');
        }
    }

    /**
     * @param callable(string):void $logger
     */
    private function rollback(string $jobId, bool $vendorSwitched, ?string $snapshotId, RecoveryComponentSelection $selection, callable $logger): bool
    {
        $ok = true;
        if ($vendorSwitched) {
            try {
                $this->vendor->rollbackSwitch($jobId, static fn (string $l, string $m) => $logger(($l === 'info' ? '' : strtoupper($l) . ': ') . $m));
                $logger('Vendor rolled back to the previous directory.');
            } catch (\Throwable $e) {
                $logger('Vendor rollback FAILED: ' . $e->getMessage());
                $ok = false;
            }
        }
        if ($snapshotId !== null) {
            $ok = $this->tryRollback($snapshotId, $selection, $logger) && $ok;
        }

        return $ok;
    }

    /**
     * @param callable(string):void $logger
     * @return list<string>
     */
    private function restoreComponents(RecoveryComponentSelection $selection, callable $logger): array
    {
        $restored = [];
        foreach (self::ORDER as $component) {
            if (!$selection->contains($component)) {
                continue;
            }
            if ($component->isDatabase()) {
                $this->restoreDatabase($logger);
            } elseif ($component->isSingleFile()) {
                $this->restoreEntries($component, false, $logger);
            } else {
                $this->restoreEntries($component, true, $logger);
            }
            $restored[] = $component->value;
            $logger('Restored ' . $component->value);
        }

        return $restored;
    }

    /**
     * @param callable(string):void $logger
     */
    private function restoreEntries(BackupComponent $component, bool $isDirectory, callable $logger): void
    {
        // Defensive: vendor must never travel through the in-place extract path.
        if ($component === BackupComponent::Vendor) {
            throw new GuardianException('Vendor must be restored through VendorRecoveryService, not in place.');
        }
        $prefix = $this->paths->prefix($component);
        if ($prefix === null) {
            return;
        }
        $entries = $this->extractor->entriesUnderPrefix($prefix);
        if ($entries === []) {
            $logger('  ' . $component->value . ': nothing in the archive, skipping.');

            return;
        }
        if ($isDirectory) {
            $target = $this->paths->target($component);
            if ($target !== null && is_dir($target)) {
                $this->wipeDirectory($target);
                $logger('  Cleared current ' . $prefix . '/');
            }
        }
        $this->extractor->extractEntries($entries, $this->paths->projectPath());
    }

    /**
     * @param callable(string):void $logger
     */
    private function restoreDatabase(callable $logger): void
    {
        if (!$this->extractor->hasEntry('database.sql')) {
            throw new GuardianException('The backup does not contain a database dump.');
        }
        $tempSql = $this->workingDirectory->resolve('restore-' . bin2hex(random_bytes(4)) . '.sql');
        try {
            $bytes = $this->extractor->extractEntryToFile('database.sql', $tempSql);
            $logger(sprintf('  Extracted database.sql (%d bytes).', $bytes));
            $this->databaseImporter->importFrom($tempSql, $logger);
        } finally {
            if (is_file($tempSql)) {
                @unlink($tempSql);
            }
        }
    }

    /**
     * @param callable(string):void $logger
     */
    private function tryRollback(string $snapshotId, RecoveryComponentSelection $selection, callable $logger): bool
    {
        try {
            $logger('Attempting rollback of non-vendor components from safety snapshot ' . $snapshotId . '…');
            $this->catalog->assertRecoverable($snapshotId);
            $this->extractor->open($this->storage->archivePath($snapshotId));
            try {
                $this->extractor->assertSafeEntries();
                // Never restore vendor from the snapshot archive; vendor rollback is atomic.
                $this->restoreComponents($this->withoutVendor($selection), $logger);
            } finally {
                $this->extractor->close();
            }
            $logger('Non-vendor rollback completed.');

            return true;
        } catch (\Throwable $e) {
            $logger('Non-vendor rollback failed: ' . $e->getMessage());

            return false;
        }
    }

    private function assertVendorStrategyFeasible(VendorRestoreStrategy $strategy, BackupManifest $manifest, string $jobId): void
    {
        if (!$strategy->touchesVendor()) {
            return;
        }
        if (!$this->vendor->canAtomicallySwitch($jobId)) {
            throw new GuardianException('Vendor recovery is blocked: an atomic vendor switch is not possible on this filesystem layout.');
        }
        if ($strategy === VendorRestoreStrategy::Rebuild) {
            if ($this->composerEnvironment->phpBinary() === null || $this->composerEnvironment->composerBinary() === null) {
                throw new GuardianException('Vendor rebuild is blocked: a PHP CLI binary and composer.phar are required.');
            }
        }
        if ($strategy === VendorRestoreStrategy::Archived && !$manifest->hasComponent(BackupComponent::Vendor)) {
            throw new GuardianException('Archived vendor restore is blocked: the backup does not contain a vendor archive.');
        }
    }

    private function snapshotSelection(RecoveryComponentSelection $selection): ComponentSelection
    {
        // Snapshot the components being changed EXCEPT vendor: the live vendor is
        // preserved by an atomic rename, so it need not (and should not) be
        // captured into a potentially large/unreliable archive.
        $request = [];
        foreach ($selection->selected() as $component) {
            if ($component !== BackupComponent::Vendor) {
                $request[$component->value] = true;
            }
        }

        return ComponentSelection::fromRequest($request);
    }

    private function withoutVendor(RecoveryComponentSelection $selection): RecoveryComponentSelection
    {
        // RecoveryComponentSelection never selects vendor for the in-place path
        // anyway; return as-is (restoreEntries also guards vendor defensively).
        return $selection;
    }

    private function databaseWasRestored(RecoveryComponentSelection $selection, array $restored): bool
    {
        return $selection->contains(BackupComponent::Database) && \in_array('database', $restored, true);
    }

    private function restoreMaintenance(bool $previous, callable $logger): void
    {
        try {
            if ($previous) {
                $this->maintenance->enable();
                $logger('Maintenance mode kept ON (it was on before recovery).');
            } else {
                $this->maintenance->disable();
                $logger('Maintenance mode disabled — site back online.');
            }
        } catch (\Throwable $e) {
            $logger('Could not restore maintenance mode: ' . $e->getMessage());
        }
    }

    private function wipeDirectory(string $dir): void
    {
        $normalised = $this->pathNormalizer->normalize($dir);
        $project = $this->paths->projectPath();
        if ($normalised === $project || !$this->pathNormalizer->isContained($project, $normalised)) {
            throw new GuardianException('Refusing to clear an unexpected path.');
        }
        if ($this->storage->contains($normalised)) {
            throw new GuardianException('Refusing to clear the Guardian backups directory.');
        }
        // Hard guard: the in-place wipe path must never touch vendor.
        if (basename($normalised) === 'vendor') {
            throw new GuardianException('Refusing to wipe the vendor directory in place.');
        }
        $this->wipeRecursive($normalised);
    }

    private function wipeRecursive(string $dir): void
    {
        $handle = @opendir($dir);
        if ($handle === false) {
            return;
        }
        try {
            while (($name = readdir($handle)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $dir . '/' . $name;
                if (is_link($path)) {
                    @unlink($path);
                    continue;
                }
                if (is_dir($path)) {
                    $this->wipeRecursive($path);
                    @rmdir($path);
                } else {
                    @unlink($path);
                }
            }
        } finally {
            closedir($handle);
        }
    }

    private function generateJobId(): string
    {
        return $this->clock->now()->format('Ymd-His') . '-' . bin2hex(random_bytes(4));
    }

    /**
     * @param list<string> $restored
     * @param list<string> $log
     */
    private function recordHistory(string $backupId, array $restored, ?string $snapshotId, string $status, string $message, array $log): void
    {
        $this->history->record([
            'id' => $this->storage->generateId($this->clock->now()),
            'at' => $this->clock->now()->format(\DATE_ATOM),
            'backup_id' => $backupId,
            'components' => $restored,
            'safety_snapshot' => $snapshotId,
            'status' => $status,
            'message' => $message,
            'log' => \array_slice($log, -200),
        ]);
    }
}
