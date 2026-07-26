<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\SelfMaintenance;

use Vtinnovations\GuardianTypo3\Application\Contract\DeferredWorkerSpawnerInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\SystemLoggerInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\Typo3ExtensionStateInterface;
use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\SelfMaintenance\SelfMaintenanceStore;

/**
 * The deferred Guardian self-maintenance workflow.
 *
 * Guardian cannot safely disable itself inside the same active request, so this
 * service records a minimal, fixed job outside the extension directory and spawns
 * a detached worker that performs the supported deactivation AFTER the response
 * completes. The worker uses TYPO3's package API (never a manual package-state
 * edit). On failure Guardian stays enabled and the failure is recorded.
 *
 * The endpoint is HARD-BOUND to the exact Guardian package identity: it is not a
 * general package-management or arbitrary-command mechanism. Any other target,
 * action or path is refused with `invalid_self_target`.
 */
final class SelfMaintenanceService
{
    public const GUARDIAN_PACKAGE = 'vtinnovations/guardian-typo3';
    public const GUARDIAN_EXTENSION_KEY = 'guardian_typo3';
    private const ID_PATTERN = '/^\d{8}-\d{6}-[a-f0-9]{8}$/';

    public function __construct(
        private readonly SelfMaintenanceStore $store,
        private readonly DeferredWorkerSpawnerInterface $spawner,
        private readonly Typo3ExtensionStateInterface $extensionState,
        private readonly ClockInterface $clock,
        private readonly SystemLoggerInterface $logger,
    ) {
    }

    /**
     * Queue the deferred self-disable and spawn the worker.
     *
     * @return array{jobId: string}
     * @throws GuardianException
     */
    public function requestDisable(string $admin): array
    {
        $current = $this->store->status();
        if ($current !== null && ($current['status'] ?? '') === 'running') {
            throw new GuardianException('self_maintenance_running');
        }

        $id = $this->clock->now()->format('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $this->store->saveJob([
            'id' => $id,
            'action' => 'disable',
            'package' => self::GUARDIAN_PACKAGE,
            'admin' => $admin,
            'created_at' => $this->clock->now()->format(\DATE_ATOM),
        ]);
        $this->store->saveStatus([
            'id' => $id,
            'action' => 'disable',
            'status' => 'queued',
            'message' => '',
            'created_at' => $this->clock->now()->format(\DATE_ATOM),
        ]);
        $this->store->writeRecoveryMetadata([
            'operation' => 'self-disable',
            'package' => self::GUARDIAN_PACKAGE,
            'extension_key' => self::GUARDIAN_EXTENSION_KEY,
            'reenable_hint' => 'Re-enable via: vendor/bin/typo3 extension:setup (or activate ' . self::GUARDIAN_EXTENSION_KEY . ' again).',
        ]);
        $this->store->appendLog('info', 'Self-disable requested by ' . $admin . ' (job ' . $id . ').');

        try {
            $this->spawner->spawn($id);
        } catch (GuardianException $e) {
            $this->store->saveStatus(['id' => $id, 'action' => 'disable', 'status' => 'failed', 'message' => $e->getMessage(), 'finished_at' => $this->clock->now()->format(\DATE_ATOM)]);
            $this->store->appendLog('error', 'Could not spawn worker: ' . $e->getMessage());
            throw $e;
        }

        return ['jobId' => $id];
    }

    /**
     * Executed by the detached CLI worker. Performs the deactivation via the
     * supported TYPO3 API and records the outcome. On failure Guardian is left
     * enabled (deactivation simply did not happen).
     */
    public function runDisable(string $jobId): void
    {
        if (preg_match(self::ID_PATTERN, $jobId) !== 1) {
            return;
        }
        $job = $this->store->job();
        if ($job === null || ($job['id'] ?? '') !== $jobId) {
            return;
        }
        $this->assertGuardianJob($job);

        $this->store->saveStatus(['id' => $jobId, 'action' => 'disable', 'status' => 'running', 'message' => '', 'started_at' => $this->clock->now()->format(\DATE_ATOM)]);
        $this->store->appendLog('info', 'Disabling Guardian via the supported TYPO3 package API…');

        try {
            $this->extensionState->deactivate(self::GUARDIAN_EXTENSION_KEY);
            $this->store->saveStatus(['id' => $jobId, 'action' => 'disable', 'status' => 'succeeded', 'message' => '', 'finished_at' => $this->clock->now()->format(\DATE_ATOM)]);
            $this->store->appendLog('info', 'Guardian disabled successfully.');
            $this->logger->info('Guardian self-disabled (job ' . $jobId . ').', 'self-maintenance');
        } catch (\Throwable $e) {
            $this->store->saveStatus(['id' => $jobId, 'action' => 'disable', 'status' => 'failed', 'message' => $e->getMessage(), 'finished_at' => $this->clock->now()->format(\DATE_ATOM)]);
            $this->store->appendLog('error', 'Self-disable failed — Guardian remains enabled: ' . $e->getMessage());
            $this->logger->error('Guardian self-disable failed (job ' . $jobId . '): ' . $e->getMessage(), 'self-maintenance');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function status(): ?array
    {
        return $this->store->status();
    }

    /**
     * Assert an intent targets ONLY Guardian. Used by the removal endpoint too,
     * so the self-maintenance surface can never remove another package.
     *
     * @throws GuardianException
     */
    public function assertGuardianIdentity(string $package): void
    {
        if ($package !== self::GUARDIAN_PACKAGE) {
            throw new GuardianException('invalid_self_target');
        }
    }

    /**
     * Record the pre-removal recovery metadata + rollback instructions OUTSIDE
     * the Guardian package directory, before Composer starts.
     */
    public function prepareRemovalRecovery(string $jobId, string $admin): void
    {
        $this->store->saveJob([
            'id' => $jobId,
            'action' => 'remove',
            'package' => self::GUARDIAN_PACKAGE,
            'admin' => $admin,
            'created_at' => $this->clock->now()->format(\DATE_ATOM),
        ]);
        $this->store->writeRecoveryMetadata([
            'operation' => 'self-remove',
            'package' => self::GUARDIAN_PACKAGE,
            'update_job' => $jobId,
            'rollback' => 'A pre-removal safety backup was created by the update job. Restore it from the Guardian Recovery panel, or manually run "composer require ' . self::GUARDIAN_PACKAGE . '" against the backed-up composer.json/lock.',
            'note' => 'This installation must NOT auto-delete packages/guardian-typo3; composer remove only unregisters the package.',
        ]);
        $this->store->appendLog('info', 'Guardian removal requested by ' . $admin . ' (update job ' . $jobId . ').');
    }

    /**
     * @param array<string, mixed> $job
     * @throws GuardianException
     */
    private function assertGuardianJob(array $job): void
    {
        if (($job['action'] ?? '') !== 'disable' || ($job['package'] ?? '') !== self::GUARDIAN_PACKAGE) {
            $this->store->appendLog('error', 'Refused a self-maintenance job that was not the Guardian disable operation.');
            throw new GuardianException('invalid_self_target');
        }
    }
}
