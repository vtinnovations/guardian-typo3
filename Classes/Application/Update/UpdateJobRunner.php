<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Update;

use Vtinnovations\GuardianTypo3\Application\Backup\BackupService;
use Vtinnovations\GuardianTypo3\Application\Backup\ComponentSelection;
use Vtinnovations\GuardianTypo3\Application\Contract\MaintenanceModeInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\SystemLoggerInterface;
use Vtinnovations\GuardianTypo3\Application\Environment\CapabilityAssertion;
use Vtinnovations\GuardianTypo3\Application\Recovery\RestoreService;
use Vtinnovations\GuardianTypo3\Domain\Backup\BackupComponent;
use Vtinnovations\GuardianTypo3\Domain\Backup\BackupType;
use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Job\Job;
use Vtinnovations\GuardianTypo3\Domain\Job\JobType;
use Vtinnovations\GuardianTypo3\Domain\Job\UpdateMode;
use Vtinnovations\GuardianTypo3\Domain\Process\CommandRequest;
use Vtinnovations\GuardianTypo3\Infrastructure\Process\SymfonyProcessCommandExecutor;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\ComposerEnvironment;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\ComposerRuntime;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\Typo3ConsoleCommands;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\UpdateJobLog;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\UpdateJobStore;

/**
 * Executes an update {@see Job}'s steps in order inside the CLI worker.
 *
 * The pipeline reuses Guardian's existing subsystems rather than re-implementing
 * them: the safety backup uses {@see BackupService}, maintenance mode uses the
 * shared {@see MaintenanceModeInterface}, and rollback uses the shared
 * {@see RestoreService} (there is NO second restore engine). Composer runs via
 * the shell-free {@see SymfonyProcessCommandExecutor}; the database schema and
 * cache steps run TYPO3's own console commands.
 *
 * Failure-safety mirrors the audited Contao runner: on a mid-update failure the
 * runner keeps maintenance mode ON, attempts a rollback from the pre-update
 * safety snapshot, restores the previous maintenance state only if it is safe,
 * and never reports success without a real update. Cache-clear failure is a
 * warning, not a hard failure.
 */
final class UpdateJobRunner
{
    public const STEP_BACKUP = 'safety_backup';
    public const STEP_MAINT_ON = 'maintenance_on';
    public const STEP_COMPOSER = 'composer';
    public const STEP_SCHEMA = 'database_schema';
    public const STEP_CACHE = 'cache_clear';
    public const STEP_VERIFY = 'verify';
    public const STEP_MAINT_OFF = 'maintenance_off';

    public function __construct(
        private readonly UpdateJobStore $store,
        private readonly UpdateJobLog $log,
        private readonly SystemLoggerInterface $sysLog,
        private readonly ComposerEnvironment $composerEnvironment,
        private readonly SymfonyProcessCommandExecutor $executor,
        private readonly Typo3ConsoleCommands $console,
        private readonly BackupService $backupService,
        private readonly MaintenanceModeInterface $maintenance,
        private readonly RestoreService $restoreService,
        private readonly ProjectEnvironmentInterface $environment,
        private readonly ClockInterface $clock,
        private readonly UpdateNotifier $notifier,
        private readonly ComposerRuntime $composerRuntime,
        private readonly \Vtinnovations\GuardianTypo3\Application\Extension\LocalPackagePreparer $localPackagePreparer,
        private readonly \Vtinnovations\GuardianTypo3\Infrastructure\Extension\ManagedExtensionRegistry $managedExtensions,
        private readonly \Vtinnovations\GuardianTypo3\Application\Extension\ManagedPackageRemover $managedPackageRemover,
        private readonly \Vtinnovations\GuardianTypo3\Infrastructure\Update\AnalysisWorkspace $analysisWorkspace,
        private readonly ComposerConflictAnalyzer $conflictAnalyzer,
        private readonly CapabilityAssertion $capability,
    ) {
    }

    public function run(Job $job): void
    {
        // The worker runs detached from the request that queued the job, so it
        // re-asserts the entitlement itself instead of trusting that queueing
        // was authorised.
        $this->capability->requirePro('Running an update job');

        $firstStep = $job->steps[0] ?? self::STEP_VERIFY;
        $job = $job->start($firstStep, $this->clock->now());
        $this->store->save($job);
        $this->sysLog->info(sprintf('Update job %s started (type: %s).', $job->id, $job->type->value), 'update');

        if ($job->type === JobType::DryRun) {
            $this->runDryRun($job);

            return;
        }

        $this->runRealUpdate($job);
    }

    private function runDryRun(Job $job): void
    {
        $restoreComposer = null;
        $analysisDir = null;
        // Safety net: capture the live composer files so any accidental live
        // change during analysis is detected and restored afterwards.
        $liveGuard = $this->snapshotLiveComposer();
        $action = (string) ($job->options['composer_action'] ?? 'update');
        try {
            $this->log->step(self::STEP_COMPOSER, 'Dry run — resolving dependencies without changing the live project.');
            $factory = $this->requireFactory(); // validates the live binaries/runtime first

            if (\in_array($action, ['require', 'remove'], true)) {
                // `composer require`/`remove` edit composer.json even with
                // --dry-run, so run them in an ISOLATED copy — the live
                // composer.json, composer.lock and vendor/ are never modified.
                $analysisDir = $this->analysisWorkspace->create($job->id);
                $this->log->info(self::STEP_COMPOSER, 'Analysing in an isolated workspace; the live composer.json, composer.lock and vendor/ are not modified.');
                $factory = $this->factoryFor($analysisDir);
            } elseif ($action === 'install_local' && \is_array($job->options['local_package'] ?? null)) {
                $this->log->info(self::STEP_COMPOSER, 'Preparing a temporary path repository for the staged package (composer.json is restored afterwards).');
                $restoreComposer = $this->localPackagePreparer->applyForDryRun($job->options['local_package']);
            }

            $request = $this->composerRequest($job, $factory, true, 600);
            $this->log->info(self::STEP_COMPOSER, '> ' . UpdateJobLog::redactSecrets($request->describe()));
            $result = $this->executor->runStreaming($request, fn (string $level, string $line) => $this->log->{$level}(self::STEP_COMPOSER, $line));
            $this->logComposerDiagnostics($result->exitCode, $result->stderr);

            if ($result->exitCode === SymfonyProcessCommandExecutor::EXIT_TIMEOUT) {
                $this->finishDryRunFailure($job, 'composer_timeout', $result->exitCode, $result->combinedOutput());

                return;
            }
            if (!$result->isSuccessful()) {
                $this->finishDryRunFailure($job, null, $result->exitCode, $result->combinedOutput());

                return;
            }

            $this->log->info(self::STEP_COMPOSER, 'Dry run finished — no live files were changed.');
            $this->finishSuccess($job, ['result' => 'dry_run_ok']);
            $this->notifier->notify('dry_run_completed', $this->baseContext($job) + ['result' => 'ok']);
        } catch (\Throwable $e) {
            $this->finishDryRunFailure($job, 'analysis_error', -1, $e->getMessage());
        } finally {
            if ($restoreComposer !== null) {
                $restoreComposer();
            }
            if ($analysisDir !== null) {
                $this->analysisWorkspace->cleanup($job->id);
            }
            $this->restoreLiveComposerIfChanged($liveGuard);
        }
    }

    /**
     * Persist a STRUCTURED dry-run failure (error code, credential-safe Composer
     * detail lines, recommendation codes and the exit code) so the UI can show
     * the real cause and next steps instead of a bare "Error".
     */
    private function finishDryRunFailure(Job $job, ?string $forcedCode, int $exitCode, string $output): void
    {
        $analysis = $this->conflictAnalyzer->analyze($exitCode, $output);
        $errorCode = $forcedCode ?? $analysis['error_code'];
        $this->log->error(self::STEP_COMPOSER, sprintf('Dry run failed: %s (composer exit %d).', $errorCode, $exitCode));
        foreach ($analysis['details'] as $line) {
            $this->log->warning(self::STEP_COMPOSER, $line);
        }
        $this->finishFailure($job, self::STEP_COMPOSER, $errorCode, null, 'not_attempted', [
            'error_code' => $errorCode,
            'details' => $analysis['details'],
            'recommendation_codes' => $forcedCode === 'composer_timeout' ? ['rec_retry_later'] : $analysis['recommendations'],
            'composer_exit_code' => $exitCode >= 0 ? $exitCode : null,
            'result_status' => 'blocked',
        ]);
    }

    /**
     * @return array{json_path: string, lock_path: string, json: ?string, lock: ?string}
     */
    private function snapshotLiveComposer(): array
    {
        $project = rtrim($this->environment->projectPath(), '/');
        $jsonPath = $project . '/composer.json';
        $lockPath = $project . '/composer.lock';

        return [
            'json_path' => $jsonPath,
            'lock_path' => $lockPath,
            'json' => is_file($jsonPath) ? (string) @file_get_contents($jsonPath) : null,
            'lock' => is_file($lockPath) ? (string) @file_get_contents($lockPath) : null,
        ];
    }

    /**
     * @param array{json_path: string, lock_path: string, json: ?string, lock: ?string} $snapshot
     */
    private function restoreLiveComposerIfChanged(array $snapshot): void
    {
        foreach ([['json', 'json_path'], ['lock', 'lock_path']] as [$key, $pathKey]) {
            $original = $snapshot[$key];
            if ($original === null) {
                continue;
            }
            $path = $snapshot[$pathKey];
            if (is_file($path) && (string) @file_get_contents($path) !== $original) {
                @file_put_contents($path, $original, \LOCK_EX);
                $this->log->warning(self::STEP_COMPOSER, 'A live Composer file was modified during analysis and has been restored: ' . basename($path) . '.');
            }
        }
    }

    private function runRealUpdate(Job $job): void
    {
        $previousMaintenance = false;
        $maintenanceEnabled = false;
        $snapshotId = null;
        // Structured Composer failure (error code, credential-safe detail lines,
        // recommendation codes, exit code) captured at the point of failure so the
        // UI shows the same actionable report a dry run does — never a bare "Error".
        $structuredFailure = [];
        // Guardian-managed source-directory removal state (quarantine path so a
        // later failure can move the owned directory back, and the verified plan).
        $quarantinePath = null;
        $managedRemoval = $this->managedRemovalPlan($job);
        $vendorIncluded = ($job->options['snapshot_vendor'] ?? true) !== false;
        $this->notifier->notify('started', $this->baseContext($job));

        try {
            // 1 — mandatory safety backup (before ANYTHING else).
            $job = $this->advance($job, self::STEP_BACKUP);
            $this->log->step(self::STEP_BACKUP, 'Creating the mandatory pre-update safety backup…');
            try {
                $selection = ComponentSelection::fromRequest([
                    'vendor' => $vendorIncluded,
                    'configuration' => true,
                    'packages' => true,
                    'templates' => true,
                ]);
                $this->log->info(self::STEP_BACKUP, 'Acquiring backup resources for internal pre-update safety backup…');
                $backup = $this->backupService->create($selection, BackupType::PreUpdate, 30);
                $snapshotId = $backup->id();
            } catch (\Throwable $e) {
                // Safety backup failed → do NOT enable maintenance, do NOT run Composer.
                throw new GuardianException('Pre-update safety backup failed — aborting before any change: ' . $e->getMessage());
            }
            $this->log->info(self::STEP_BACKUP, 'Safety backup created: ' . $snapshotId . ($vendorIncluded ? ' (includes vendor/)' : ' (composer + database + config)'));

            // 2 — maintenance mode (remember previous state).
            $job = $this->advance($job, self::STEP_MAINT_ON);
            $previousMaintenance = $this->maintenance->isEnabled();
            $this->maintenance->enable();
            $maintenanceEnabled = true;
            $this->log->info(self::STEP_MAINT_ON, 'Maintenance mode enabled (previous state: ' . ($previousMaintenance ? 'on' : 'off') . ').');

            // 3 — Composer.
            $job = $this->advance($job, self::STEP_COMPOSER);
            $factory = $this->requireFactory();
            $composerAction = (string) ($job->options['composer_action'] ?? 'update');
            // For a staged local install, copy the validated package into
            // packages/<safe-dir> and point composer.json at it BEFORE Composer
            // resolves. The mandatory safety backup already captured composer
            // files + packages/ + vendor, so a failure rolls all of this back.
            if ($composerAction === 'install_local' && \is_array($job->options['local_package'] ?? null)) {
                $this->log->info(self::STEP_COMPOSER, 'Copying the validated extension into packages/ and registering the Composer path repository…');
                $this->localPackagePreparer->applyForInstall($job->options['local_package']);
            }
            // For a Guardian-managed uploaded-extension removal with the source
            // directory opted in, remove only this package's options.versions pin
            // and MOVE the owned directory into a private quarantine BEFORE
            // Composer runs. The safety backup already captured composer.json +
            // packages/, so any later failure rolls all of this back and the
            // quarantine is restored/cleaned up in the catch/finally.
            if ($composerAction === 'remove' && $managedRemoval !== null) {
                $this->managedPackageRemover->removeVersionMapping((string) $managedRemoval['package']);
                $this->log->info(self::STEP_COMPOSER, 'Removed the Guardian path-repository version pin for ' . (string) $managedRemoval['package'] . '.');
                $quarantinePath = $this->managedPackageRemover->quarantine($managedRemoval, $job->id);
                $this->log->info(self::STEP_COMPOSER, 'Moved the Guardian-owned source directory to a private quarantine pending successful removal.');
            }
            $mode = UpdateMode::fromString((string) ($job->options['update_mode'] ?? 'full'));
            $action = \in_array($composerAction, ['remove', 'require', 'install_local'], true) ? $composerAction : $mode->value;
            $lockBefore = $this->lockHash();
            $request = $this->composerRequest($job, $factory, false, 1800);
            $this->log->step(self::STEP_COMPOSER, sprintf('Running Composer (action: %s)…', $action));
            $this->log->info(self::STEP_COMPOSER, '> ' . UpdateJobLog::redactSecrets($request->describe()));
            $result = $this->executor->runStreaming($request, fn (string $level, string $line) => $this->log->{$level}(self::STEP_COMPOSER, $line));
            $this->logComposerDiagnostics($result->exitCode, $result->stderr);
            if ($result->exitCode === SymfonyProcessCommandExecutor::EXIT_TIMEOUT) {
                $structuredFailure = [
                    'error_code' => 'composer_timeout',
                    'details' => [],
                    'recommendation_codes' => ['rec_retry_later'],
                    'composer_exit_code' => $result->exitCode >= 0 ? $result->exitCode : null,
                    'result_status' => 'failed',
                ];
                throw new GuardianException('Composer timed out.');
            }
            if (!$result->isSuccessful()) {
                // Same structured analysis as the dry run so the live failure shows
                // the real cause + next steps instead of only "Error".
                $analysis = $this->conflictAnalyzer->analyze($result->exitCode, $result->combinedOutput());
                $structuredFailure = [
                    'error_code' => $analysis['error_code'],
                    'details' => $analysis['details'],
                    'recommendation_codes' => $analysis['recommendations'],
                    'composer_exit_code' => $result->exitCode >= 0 ? $result->exitCode : null,
                    'result_status' => 'failed',
                ];
                throw new GuardianException('Composer failed with exit code ' . $result->exitCode . '.');
            }
            $lockAfter = $this->lockHash();
            $this->log->info(self::STEP_COMPOSER, $lockBefore === $lockAfter
                ? 'composer.lock unchanged — packages were already at the highest allowed versions.'
                : 'composer.lock updated — package versions changed.');
            $this->assertComposerFilesValid();

            // 4 — database schema (additive changes only).
            $job = $this->advance($job, self::STEP_SCHEMA);
            $this->log->step(self::STEP_SCHEMA, 'Setting up TYPO3 extensions and applying required database schema changes…');
            $schema = $this->executor->runStreaming($this->console->schemaUpdate(), fn (string $level, string $line) => $this->log->{$level}(self::STEP_SCHEMA, $line));
            if ($schema->exitCode === SymfonyProcessCommandExecutor::EXIT_TIMEOUT) {
                throw new GuardianException('TYPO3 extension setup timed out.');
            }
            if (!$schema->isSuccessful()) {
                throw new GuardianException('TYPO3 extension setup failed (exit ' . $schema->exitCode . ').');
            }

            // 5 — cache flush (failure is a warning, not fatal).
            $job = $this->advance($job, self::STEP_CACHE);
            $this->log->step(self::STEP_CACHE, 'Flushing TYPO3 caches…');
            $cache = $this->executor->runStreaming($this->console->cacheFlush(), fn (string $level, string $line) => $this->log->{$level}(self::STEP_CACHE, $line));
            if (!$cache->isSuccessful()) {
                $this->log->warning(self::STEP_CACHE, 'Cache flush reported a problem (exit ' . $cache->exitCode . ') — continuing.');
            }

            // 6 — verification.
            $job = $this->advance($job, self::STEP_VERIFY);
            $this->log->step(self::STEP_VERIFY, 'Verifying the resulting installation…');
            $this->verifyInstallation();
            $resultTypo3 = $this->environment->typo3Version();
            $this->log->info(self::STEP_VERIFY, 'Resulting TYPO3 version: ' . $resultTypo3);

            // 7 — restore previous maintenance state.
            $job = $this->advance($job, self::STEP_MAINT_OFF);
            $this->restoreMaintenance($previousMaintenance);

            // Record Guardian-managed ownership for a successful custom install so
            // a later Remove can prove Guardian created the packages/ directory.
            if ($composerAction === 'install_local' && \is_array($job->options['local_package'] ?? null)) {
                $this->recordManagedOwnership($job->options['local_package'], (string) ($job->options['admin'] ?? 'admin'), $snapshotId);
            }
            // Managed removal fully succeeded → delete the quarantined directory
            // and forget the ownership record (step 16: only after complete success).
            $sourceRemoved = false;
            if ($composerAction === 'remove' && $managedRemoval !== null) {
                $this->managedPackageRemover->commitQuarantine($job->id);
                $this->managedPackageRemover->forget((string) $managedRemoval['package']);
                $sourceRemoved = true;
                $this->log->info(self::STEP_MAINT_OFF, 'Deleted the quarantined Guardian-owned source directory and removed the ownership record.');
            }

            $this->finishSuccess($job, [
                'safety_backup' => $snapshotId,
                'result_typo3' => $resultTypo3,
                // Only meaningful for a managed uploaded-extension removal; a
                // registration-only removal (unverified / not opted in) leaves it null.
                'source_removed' => $managedRemoval !== null ? ($sourceRemoved ? 'removed' : 'retained') : null,
            ]);
            $this->notifier->notify('succeeded', $this->baseContext($job) + ['safety_backup' => $snapshotId, 'result_typo3' => $resultTypo3, 'result' => 'ok']);
        } catch (\Throwable $e) {
            $failedStep = $job->currentStep ?? self::STEP_BACKUP;
            $this->log->error($failedStep, 'Step failed: ' . $e->getMessage());
            $this->sysLog->error(sprintf('Update job %s failed at %s: %s', $job->id, $failedStep, $e->getMessage()), 'update');

            $rollbackResult = 'not_attempted';
            // Only roll back once Composer may have changed the tree.
            if ($snapshotId !== null && \in_array($failedStep, [self::STEP_COMPOSER, self::STEP_SCHEMA, self::STEP_CACHE, self::STEP_VERIFY], true)) {
                $rollbackResult = $this->rollback($job, $snapshotId, $vendorIncluded, $previousMaintenance);
            } elseif ($maintenanceEnabled) {
                // Failure before Composer changed anything — just restore maintenance.
                $this->restoreMaintenance($previousMaintenance);
            }
            // A managed removal failed: put the quarantined source directory back
            // (the safety-backup rollback also restores composer.json, composer.lock
            // and the version mapping), then discard the now-redundant quarantine.
            if ($quarantinePath !== null && $managedRemoval !== null) {
                $this->managedPackageRemover->restoreQuarantine($quarantinePath, (string) $managedRemoval['path']);
                $this->managedPackageRemover->commitQuarantine($job->id);
                $this->log->info($failedStep, 'Restored the Guardian-owned source directory after the failed removal.');
            }

            $this->finishFailure($job, $failedStep, $e->getMessage(), $snapshotId, $rollbackResult, $structuredFailure);
        }
    }

    private function rollback(Job $job, string $snapshotId, bool $vendorIncluded, bool $previousMaintenance): string
    {
        $this->log->step('rollback', 'Rolling back from the pre-update safety snapshot ' . $snapshotId . '…');
        $this->notifier->notify('rollback_started', $this->baseContext($job) + ['safety_backup' => $snapshotId]);
        if (!$vendorIncluded) {
            $this->log->warning('rollback', 'vendor/ was NOT in the safety snapshot — Composer dependencies may need a controlled "composer install" afterwards to match the restored composer.lock.');
        }
        try {
            // Reuse the shared Recovery engine. No second snapshot (createSnapshot=false).
            $request = [];
            foreach (BackupComponent::cases() as $component) {
                $request[$component->value] = true;
            }
            // Reuse the hardened recovery engine: rebuild vendor from the restored
            // lock and switch atomically (never an in-place vendor overwrite).
            $this->restoreService->restore($snapshotId, $request, true, true, 'rebuild');
            $this->log->info('rollback', 'Rollback completed from ' . $snapshotId . '.');
            $this->restoreMaintenance($previousMaintenance);
            $this->notifier->notify('rollback_succeeded', $this->baseContext($job) + ['safety_backup' => $snapshotId]);

            return 'succeeded';
        } catch (\Throwable $e) {
            // Keep maintenance ON: the site is in an uncertain state.
            $this->log->error('rollback', 'Rollback failed: ' . $e->getMessage() . ' — maintenance mode is left ON for manual recovery.');
            $this->notifier->notify('rollback_failed', $this->baseContext($job) + ['safety_backup' => $snapshotId, 'reason' => $e->getMessage()]);

            return 'failed';
        }
    }

    private function restoreMaintenance(bool $previousMaintenance): void
    {
        try {
            if ($previousMaintenance) {
                $this->maintenance->enable();
                $this->log->info(self::STEP_MAINT_OFF, 'Maintenance mode kept ON (it was on before the update).');
            } else {
                $this->maintenance->disable();
                $this->log->info(self::STEP_MAINT_OFF, 'Maintenance mode disabled — site back online.');
            }
        } catch (\Throwable $e) {
            $this->log->error(self::STEP_MAINT_OFF, 'Could not restore maintenance mode: ' . $e->getMessage());
        }
    }

    private function verifyInstallation(): void
    {
        $project = rtrim($this->environment->projectPath(), '/');
        if (!is_file($project . '/vendor/autoload.php')) {
            throw new GuardianException('Post-update verification failed: vendor/autoload.php is missing.');
        }
        $this->assertComposerFilesValid();
    }

    private function assertComposerFilesValid(): void
    {
        $project = rtrim($this->environment->projectPath(), '/');
        foreach (['composer.json', 'composer.lock'] as $name) {
            $path = $project . '/' . $name;
            if (!is_file($path)) {
                throw new GuardianException('Post-update verification failed: ' . $name . ' is missing.');
            }
            if (!\is_array(json_decode((string) @file_get_contents($path), true))) {
                throw new GuardianException('Post-update verification failed: ' . $name . ' is not valid JSON.');
            }
        }
    }

    private function requireFactory(): ComposerCommandFactory
    {
        return $this->factoryFor(rtrim($this->environment->projectPath(), '/'));
    }

    /**
     * Build a Composer command factory whose working directory is $projectDir —
     * the live project for real runs, or an isolated analysis workspace for a
     * dry run. Binaries + runtime + manifest are validated for that directory.
     */
    private function factoryFor(string $projectDir): ComposerCommandFactory
    {
        $php = $this->composerEnvironment->phpBinary();
        $composer = $this->composerEnvironment->composerBinary();
        if ($php === null) {
            throw new GuardianException('No PHP CLI binary is configured. Set it in the Guardian settings.');
        }
        if ($composer === null) {
            throw new GuardianException('No composer.phar was found. Place one in the project root or configure its path.');
        }

        // Validate the runtime + binaries + manifest and initialise HOME/COMPOSER_HOME
        // before Composer starts (precise error codes; never touches the network).
        $this->composerRuntime->preflight($php, $composer, $projectDir);

        return new ComposerCommandFactory($php, $composer, $projectDir);
    }

    /**
     * Logs protected, credential-free Composer runtime diagnostics and surfaces
     * the old-Composer "development build" notice as a RECOMMENDATION (never as
     * the HOME failure, and never blocking).
     */
    private function logComposerDiagnostics(int $exitCode, string $stderr): void
    {
        $diag = $this->composerRuntime->diagnostics($exitCode, $stderr);
        $this->log->info(self::STEP_COMPOSER, sprintf(
            'Composer runtime diagnostics: HOME=%s, COMPOSER_HOME=%s, writable=%s, exit=%d.',
            $diag['home_configured'] ? 'set' : 'unset',
            $diag['composer_home_configured'] ? 'set' : 'unset',
            $diag['runtime_writable'] ? 'yes' : 'no',
            $diag['exit_code'],
        ));
        if ($diag['composer_recommendation'] !== null) {
            $this->log->warning(self::STEP_COMPOSER, $diag['composer_recommendation']);
        }
    }

    /**
     * @return list<string>
     */
    private function selectedPackages(Job $job): array
    {
        $packages = $job->options['packages'] ?? [];

        return \is_array($packages) ? array_values(array_filter(array_map(static fn ($p): string => \is_string($p) ? $p : '', $packages))) : [];
    }

    /**
     * Build the Composer request for the job's action. All actions share the
     * identical backup → maintenance → composer → schema → cache → verify →
     * rollback pipeline:
     *   remove         → composer remove <pkg…>
     *   require         → composer require <req…>          (TER / Packagist install)
     *   install_local   → composer update <name> --with-dependencies
     *                     (after composer.json was pointed at the staged path repo)
     *   update (default) → composer update … (full / patch / selective)
     */
    private function composerRequest(Job $job, ComposerCommandFactory $factory, bool $dryRun, int $timeout): CommandRequest
    {
        $packages = $this->selectedPackages($job);
        $ignore = $this->composerEnvironment->ignorePlatformFlags();
        $action = (string) ($job->options['composer_action'] ?? 'update');

        return match ($action) {
            'remove' => $factory->remove($packages, $dryRun, $ignore, $timeout),
            'require' => $factory->require($packages, $dryRun, $ignore, $timeout),
            'install_local' => $factory->forMode(UpdateMode::Selective, $packages, $dryRun, $ignore, $timeout),
            default => $factory->forMode(UpdateMode::fromString((string) ($job->options['update_mode'] ?? 'full')), $packages, $dryRun, $ignore, $timeout),
        };
    }

    /**
     * Re-derive and re-verify the Guardian-managed removal plan inside the worker
     * (defence in depth: never trust the request-side flag alone). Returns the
     * verified plan only when the job opted into source deletion AND Guardian can
     * still prove ownership; otherwise null (registration-only removal).
     *
     * @return array<string, mixed>|null
     */
    private function managedRemovalPlan(Job $job): ?array
    {
        if ((string) ($job->options['composer_action'] ?? '') !== 'remove') {
            return null;
        }
        if (($job->options['delete_source'] ?? false) !== true) {
            return null;
        }
        $packages = $this->selectedPackages($job);
        $package = $packages[0] ?? '';
        if ($package === '') {
            return null;
        }
        $plan = $this->managedPackageRemover->plan($package);

        return ($plan['ownership_verified'] ?? false) === true ? $plan : null;
    }

    /**
     * @param array<string, mixed> $local
     */
    private function recordManagedOwnership(array $local, string $admin, ?string $snapshotId): void
    {
        try {
            $package = (string) ($local['composer_name'] ?? '');
            $absPath = $this->localPackagePreparer->targetPath($local);
            // Write a proof-of-ownership marker INTO the installed directory so a
            // later removal can prove Guardian created exactly this directory.
            $marker = $this->managedPackageRemover->writeOwnershipMarker($absPath, $package);
            $project = rtrim($this->environment->projectPath(), '/') . '/';
            $this->managedExtensions->record([
                'package' => $package,
                'extension_key' => (string) ($local['extension_key'] ?? ''),
                'version' => (string) ($local['version'] ?? ''),
                'path' => $absPath,
                'source_relative' => str_starts_with($absPath, $project) ? substr($absPath, \strlen($project)) : 'packages/' . basename($absPath),
                'checksum' => (string) ($local['checksum'] ?? ''),
                'admin' => $admin,
                'guardian_owned' => true,
                'ownership_marker' => $marker,
                'safety_backup' => $snapshotId,
            ]);
            // Best-effort cleanup of the private staging copy after a success.
            $staging = (string) ($local['staging_path'] ?? '');
            if ($staging !== '' && is_dir(\dirname($staging))) {
                $this->log->info(self::STEP_MAINT_OFF, 'Recorded Guardian-managed ownership for ' . (string) ($local['composer_name'] ?? '') . '.');
            }
        } catch (\Throwable $e) {
            $this->log->warning(self::STEP_MAINT_OFF, 'Could not record managed-ownership metadata: ' . $e->getMessage());
        }
    }

    private function lockHash(): ?string
    {
        $lock = rtrim($this->environment->projectPath(), '/') . '/composer.lock';

        return is_file($lock) ? md5_file($lock) : null;
    }

    private function advance(Job $job, string $step): Job
    {
        $job = $job->advanceTo($step);
        $this->store->save($job);

        return $job;
    }

    /**
     * @param array<string, scalar|null> $extra
     */
    private function finishSuccess(Job $job, array $extra = []): void
    {
        $job = $job->succeed($this->clock->now());
        $job = $this->withOptions($job, $extra);
        $this->store->save($job);
        $this->store->archive($job);
        $this->log->info('runner', 'Job ' . $job->id . ' completed successfully.');
        $this->sysLog->info('Update job ' . $job->id . ' completed successfully.', 'update');
    }

    /**
     * @param array<string, mixed> $structured extra structured result (error_code, details, …)
     */
    private function finishFailure(Job $job, string $step, string $error, ?string $snapshotId = null, string $rollbackResult = 'not_attempted', array $structured = []): void
    {
        $job = $job->fail($step, $error, $this->clock->now());
        $job = $this->withOptions($job, ['safety_backup' => $snapshotId, 'rollback_result' => $rollbackResult] + $structured);
        $this->store->save($job);
        $this->store->archive($job);
        $this->notifier->notify('failed', $this->baseContext($job) + ['reason' => $error, 'safety_backup' => $snapshotId, 'rollback_result' => $rollbackResult]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function withOptions(Job $job, array $extra): Job
    {
        return new Job(
            id: $job->id,
            type: $job->type,
            status: $job->status,
            steps: $job->steps,
            currentStep: $job->currentStep,
            failedStep: $job->failedStep,
            error: $job->error,
            createdAt: $job->createdAt,
            startedAt: $job->startedAt,
            finishedAt: $job->finishedAt,
            options: array_merge($job->options, array_filter($extra, static fn ($v) => $v !== null)),
        );
    }

    /**
     * @return array<string, scalar|list<string>>
     */
    private function baseContext(Job $job): array
    {
        return [
            'job_id' => $job->id,
            'mode' => (string) ($job->options['update_mode'] ?? 'full'),
            'execution_type' => $job->type === JobType::DryRun ? 'dry_run' : 'real_update',
            'display_label' => $job->type === JobType::DryRun ? 'Dry run' : 'Real update',
            'admin' => (string) ($job->options['admin'] ?? 'unknown'),
            'previous_typo3' => (string) ($job->options['previous_typo3'] ?? ''),
            'packages' => $this->selectedPackages($job),
        ];
    }
}
