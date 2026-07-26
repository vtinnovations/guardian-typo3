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

use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Job\Job;
use Vtinnovations\GuardianTypo3\Domain\Job\JobType;
use Vtinnovations\GuardianTypo3\Domain\Job\UpdateMode;
use Vtinnovations\GuardianTypo3\Domain\Update\PackageName;
use Vtinnovations\GuardianTypo3\Domain\Update\PackageRequirement;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\UpdateJobLog;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\UpdateJobStore;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\UpdateWorkerSpawner;

/**
 * Orchestrates update jobs from the backend: it validates the request, enforces
 * that only one job runs at a time, records the safe metadata, resets the live
 * log and spawns the detached worker. The heavy lifting happens in the worker
 * ({@see UpdateJobRunner}); this service is the thin, synchronous entry point the
 * AJAX controller calls, so a long update never runs inside a browser request.
 */
final class UpdateService
{
    private const UPDATE_STEPS = [
        UpdateJobRunner::STEP_BACKUP,
        UpdateJobRunner::STEP_MAINT_ON,
        UpdateJobRunner::STEP_COMPOSER,
        UpdateJobRunner::STEP_SCHEMA,
        UpdateJobRunner::STEP_CACHE,
        UpdateJobRunner::STEP_VERIFY,
        UpdateJobRunner::STEP_MAINT_OFF,
    ];
    private const DRY_RUN_STEPS = [UpdateJobRunner::STEP_COMPOSER, UpdateJobRunner::STEP_VERIFY];

    public function __construct(
        private readonly UpdateJobStore $store,
        private readonly UpdateJobLog $log,
        private readonly ClockInterface $clock,
        private readonly UpdateWorkerSpawner $spawner,
        private readonly ProjectEnvironmentInterface $environment,
    ) {
    }

    /**
     * @param list<string> $packages
     * @throws GuardianException
     */
    public function startDryRun(string $mode, array $packages): Job
    {
        return $this->create(JobType::DryRun, self::DRY_RUN_STEPS, $this->options($mode, $packages, [
            'dry_run' => true,
        ]));
    }

    /**
     * @param list<string> $packages
     * @throws GuardianException
     */
    public function startUpdate(string $mode, array $packages, bool $snapshotVendor, string $admin): Job
    {
        return $this->create(JobType::Update, self::UPDATE_STEPS, $this->options($mode, $packages, [
            'snapshot_vendor' => $snapshotVendor,
            'admin' => $admin,
        ]));
    }

    /**
     * Dry-run a `composer remove` for the Dashboard package manager. Reuses the
     * identical dry-run job pipeline (no files change) so the impact is shown
     * before any confirmation.
     *
     * @param list<string> $packages
     * @throws GuardianException
     */
    public function startRemoveDryRun(array $packages): Job
    {
        return $this->create(JobType::DryRun, self::DRY_RUN_STEPS, $this->removeOptions($packages, [
            'dry_run' => true,
        ]));
    }

    /**
     * Perform a real `composer remove` through the full safety pipeline
     * (mandatory backup → maintenance → composer remove → extension setup →
     * caches → verify → rollback on failure).
     *
     * @param list<string> $packages
     * @throws GuardianException
     */
    public function startRemove(array $packages, bool $snapshotVendor, string $admin, bool $deleteSource = false): Job
    {
        return $this->create(JobType::Update, self::UPDATE_STEPS, $this->removeOptions($packages, [
            'snapshot_vendor' => $snapshotVendor,
            'admin' => $admin,
            'delete_source' => $deleteSource,
        ]));
    }

    /**
     * Dry-run a `composer require` for a TER/Packagist package install.
     *
     * @throws GuardianException
     */
    public function startTerInstallDryRun(string $requirement): Job
    {
        return $this->create(JobType::DryRun, self::DRY_RUN_STEPS, $this->requireOptions($requirement, ['dry_run' => true]));
    }

    /**
     * Install a TER/Packagist package through the full safety pipeline.
     *
     * @throws GuardianException
     */
    public function startTerInstall(string $requirement, bool $snapshotVendor, string $admin): Job
    {
        return $this->create(JobType::Update, self::UPDATE_STEPS, $this->requireOptions($requirement, [
            'snapshot_vendor' => $snapshotVendor,
            'admin' => $admin,
        ]));
    }

    /**
     * Dry-run the installation of a staged custom local extension package.
     *
     * @param array<string, mixed> $localPackage
     * @throws GuardianException
     */
    public function startLocalInstallDryRun(array $localPackage): Job
    {
        return $this->create(JobType::DryRun, self::DRY_RUN_STEPS, $this->localInstallOptions($localPackage, ['dry_run' => true]));
    }

    /**
     * Install a staged custom local extension package through the full pipeline.
     *
     * @param array<string, mixed> $localPackage
     * @throws GuardianException
     */
    public function startLocalInstall(array $localPackage, bool $snapshotVendor, string $admin): Job
    {
        return $this->create(JobType::Update, self::UPDATE_STEPS, $this->localInstallOptions($localPackage, [
            'snapshot_vendor' => $snapshotVendor,
            'admin' => $admin,
        ]));
    }

    /**
     * Snapshot of the active job for polling, or null when idle.
     *
     * @return array<string, mixed>|null
     */
    public function status(): ?array
    {
        $job = $this->store->current();
        if ($job === null) {
            return null;
        }

        return $this->jobToPublic($job) + ['stale' => $this->store->isStale($job)];
    }

    /**
     * @return array{entries: list<array<string, mixed>>, offset: int}
     */
    public function readLog(int $offset): array
    {
        return $this->log->readSince($offset);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 20): array
    {
        $out = [];
        foreach ($this->store->listArchive($limit) as $data) {
            $out[] = $this->jobToPublic(Job::fromArray($data));
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function details(string $jobId): ?array
    {
        $job = $this->store->getArchived($jobId);

        return $job === null ? null : $this->jobToPublic($job);
    }

    /**
     * @param list<string> $steps
     * @param array<string, mixed> $options
     * @throws GuardianException
     */
    private function create(JobType $type, array $steps, array $options): Job
    {
        $current = $this->store->current();
        if ($current !== null && !$current->isFinished()) {
            if ($this->store->isStale($current)) {
                $this->store->archive($current->fail('worker', 'The previous worker never started or went stale.', $this->clock->now()));
            } else {
                throw new GuardianException('Another update job is already running. Please wait for it to finish.');
            }
        } elseif ($current !== null) {
            $this->store->archive($current);
        }

        $job = Job::queue($this->store->generateId(), $type, $steps, $this->clock->now(), $options);
        $this->store->save($job);

        $this->log->reset();
        $this->log->info('manager', sprintf('Job %s queued (type: %s, mode: %s).', $job->id, $type->value, $options['update_mode'] ?? 'full'));

        try {
            $this->spawner->spawn($job->id);
        } catch (GuardianException $e) {
            $failed = $job->fail('manager', $e->getMessage(), $this->clock->now());
            $this->log->error('manager', $e->getMessage());
            $this->store->archive($failed);
            throw $e;
        }

        return $job;
    }

    /**
     * @param list<string> $packages
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     * @throws GuardianException
     */
    private function options(string $mode, array $packages, array $extra): array
    {
        $updateMode = UpdateMode::fromString($mode);
        $validated = [];
        if ($updateMode === UpdateMode::Selective) {
            $validated = PackageName::validateList($packages);
            if ($validated === []) {
                throw new GuardianException('Selective mode requires at least one package to be selected.');
            }
        }

        return array_merge([
            'update_mode' => $updateMode->value,
            'packages' => $validated,
            'previous_typo3' => $this->environment->typo3Version(),
        ], $extra);
    }

    /**
     * @param list<string> $packages
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     * @throws GuardianException
     */
    private function removeOptions(array $packages, array $extra): array
    {
        $validated = PackageName::validateList($packages);
        if ($validated === []) {
            throw new GuardianException('A package removal requires at least one package.');
        }

        return array_merge([
            'update_mode' => UpdateMode::Selective->value,
            'composer_action' => 'remove',
            'packages' => $validated,
            'previous_typo3' => $this->environment->typo3Version(),
        ], $extra);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     * @throws GuardianException
     */
    private function requireOptions(string $requirement, array $extra): array
    {
        $validated = PackageRequirement::fromString($requirement)->toArgument();

        return array_merge([
            'update_mode' => UpdateMode::Selective->value,
            'composer_action' => 'require',
            'packages' => [$validated],
            'previous_typo3' => $this->environment->typo3Version(),
        ], $extra);
    }

    /**
     * @param array<string, mixed> $localPackage
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     * @throws GuardianException
     */
    private function localInstallOptions(array $localPackage, array $extra): array
    {
        $name = PackageName::fromString((string) ($localPackage['composer_name'] ?? ''))->value;
        if (!\is_string($localPackage['staging_path'] ?? null) || ($localPackage['staging_path'] ?? '') === '') {
            throw new GuardianException('A local install requires a staged package path.');
        }

        return array_merge([
            'update_mode' => UpdateMode::Selective->value,
            'composer_action' => 'install_local',
            'packages' => [$name],
            'local_package' => $localPackage,
            'previous_typo3' => $this->environment->typo3Version(),
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Authoritative per-step state list, parallel to $job->steps. Values are the
     * language-neutral codes the UI renders directly: 'complete', 'active',
     * 'failed' or 'pending'. Derived only from persisted job facts (status +
     * current/failed step), so it is correct for a finished job regardless of
     * where the last incremental status poll happened to observe it.
     *
     * @return list<string>
     */
    private function stepStates(Job $job): array
    {
        $steps = $job->steps;
        $status = $job->status->value;
        $curIdx = $job->currentStep !== null ? array_search($job->currentStep, $steps, true) : false;
        $failedIdx = $job->failedStep !== null ? array_search($job->failedStep, $steps, true) : false;

        $states = [];
        foreach ($steps as $i => $_step) {
            if ($status === 'succeeded') {
                $states[] = 'complete';
                continue;
            }
            if ($status === 'failed') {
                $marker = $failedIdx !== false ? $failedIdx : $curIdx;
                if ($marker === false) {
                    $states[] = 'pending';
                } elseif ($i < $marker) {
                    $states[] = 'complete';
                } else {
                    $states[] = $i === $marker ? 'failed' : 'pending';
                }
                continue;
            }
            // queued / running.
            if ($curIdx === false) {
                $states[] = 'pending';
            } elseif ($i < $curIdx) {
                $states[] = 'complete';
            } else {
                $states[] = $i === $curIdx ? 'active' : 'pending';
            }
        }

        return $states;
    }

    private function jobToPublic(Job $job): array
    {
        $total = max(1, \count($job->steps));
        $done = $job->isFinished()
            ? $total
            : ($job->currentStep !== null ? array_search($job->currentStep, $job->steps, true) : 0);
        $done = \is_int($done) ? $done : 0;

        return [
            'id' => $job->id,
            'type' => $job->type->value,
            'status' => $job->status->value,
            'steps' => $job->steps,
            // Authoritative per-step visual state parallel to `steps`, so the UI
            // never has to guess from the last incremental poll: a terminal
            // success marks every step complete; a failure marks the steps before
            // the failed one complete, the failed one failed, and the rest pending.
            'step_states' => $this->stepStates($job),
            'current_step' => $job->currentStep,
            'failed_step' => $job->failedStep,
            'error' => $job->error,
            'progress' => (int) round(($done / $total) * 100),
            'mode' => (string) ($job->options['update_mode'] ?? 'full'),
            'action' => (string) ($job->options['composer_action'] ?? 'update'),
            'packages' => array_values(array_filter((array) ($job->options['packages'] ?? []), 'is_string')),
            'execution_type' => $job->type->value === 'dry_run' ? 'dry_run' : 'real_update',
            'display_label' => $job->type->value === 'dry_run' ? 'Dry Run' : 'Live Update',
            'safety_backup' => $job->options['safety_backup'] ?? null,
            'rollback_result' => $job->options['rollback_result'] ?? null,
            'source_removed' => $job->options['source_removed'] ?? null,
            'previous_typo3' => $job->options['previous_typo3'] ?? null,
            'result_typo3' => $job->options['result_typo3'] ?? null,
            'created_at' => $job->createdAt?->format(\DATE_ATOM),
            'finished_at' => $job->finishedAt?->format(\DATE_ATOM),
            // Structured failure result (language-neutral codes) so the UI can
            // show the real cause + next steps instead of a bare "Error".
            'result_status' => $job->options['result_status'] ?? ($job->status->value === 'failed' ? 'failed' : null),
            'errorCode' => \is_string($job->options['error_code'] ?? null) ? $job->options['error_code'] : null,
            'details' => array_values(array_filter((array) ($job->options['details'] ?? []), 'is_string')),
            'recommendations' => array_values(array_filter((array) ($job->options['recommendation_codes'] ?? []), 'is_string')),
            'composerExitCode' => \is_int($job->options['composer_exit_code'] ?? null) ? $job->options['composer_exit_code'] : null,
            'logAvailable' => true,
        ];
    }
}
