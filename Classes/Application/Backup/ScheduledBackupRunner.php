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

use Vtinnovations\GuardianTypo3\Application\Contract\ScheduleConfigStoreInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\SystemLoggerInterface;
use Vtinnovations\GuardianTypo3\Application\Environment\CapabilityAssertion;
use Vtinnovations\GuardianTypo3\Domain\Backup\BackupType;
use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Schedule\BackupSchedule;
use Vtinnovations\GuardianTypo3\Domain\Schedule\ScheduleEvaluator;
use Vtinnovations\GuardianTypo3\Domain\Schedule\ScheduleRun;

/**
 * Orchestrates the two scheduled-backup profiles (Mini + Full), ported from the
 * audited Contao ScheduledBackupRunner. It builds the correct component
 * selection for a profile, delegates the heavy lifting to {@see BackupService}
 * (which owns the single global backup lock), applies the profile's retention,
 * records the run state, and sends notifications.
 *
 * {@see self::runProfile()} backs the "run now" buttons; {@see self::runDue()}
 * is what a TYPO3 Scheduler task / CLI cron calls to run whatever is due.
 */
final class ScheduledBackupRunner
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly ScheduleConfigStoreInterface $configStore,
        private readonly ScheduleEvaluator $evaluator,
        private readonly BackupNotificationService $notifier,
        private readonly ClockInterface $clock,
        private readonly SystemLoggerInterface $systemLogger,
        private readonly CapabilityAssertion $capability,
    ) {
    }

    /**
     * Forces a run of one profile, ignoring the schedule (the "run now" button).
     *
     * @param 'mini'|'full' $type
     * @throws GuardianException on failure
     */
    public function runProfile(string $type): BackupResult
    {
        $this->capability->requirePro('Running a scheduled backup profile');

        $backupType = $type === 'full' ? BackupType::Full : BackupType::Mini;
        $config = $this->configStore->loadConfig();
        $slot = \is_array($config[$type] ?? null) ? $config[$type] : [];
        $retention = (int) ($slot['retention'] ?? ($type === 'full' ? 4 : 7));

        $selection = $backupType === BackupType::Full
            ? ComponentSelection::forFull(\is_array($slot['components'] ?? null) ? $slot['components'] : [])
            : ComponentSelection::mini();

        try {
            $result = $this->backupService->create($selection, $backupType, $retention);
            $this->configStore->recordRun($type, 'success', 'Backup created: ' . $result->id(), $result->id());
            $this->notifier->notifySuccess($backupType, $result->manifest);

            return $result;
        } catch (GuardianException $e) {
            $this->configStore->recordRun($type, 'error', $e->getMessage(), null);
            $this->notifier->notifyFailure($backupType, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Runs whichever profiles are currently due.
     *
     * @return array<string, array{ran: bool, status: string, message: string}>
     */
    public function runDue(?\DateTimeImmutable $now = null): array
    {
        $this->capability->requirePro('Running scheduled backups');

        $now ??= $this->clock->now();
        $config = $this->configStore->loadConfig();
        $state = $this->configStore->loadState();

        $results = [];
        foreach (['mini', 'full'] as $type) {
            $schedule = BackupSchedule::fromArray(\is_array($config[$type] ?? null) ? $config[$type] : []);
            $run = ScheduleRun::fromArray($state[$type] ?? null);

            if (!$this->evaluator->isDue($schedule, $run, $now)) {
                $results[$type] = ['ran' => false, 'status' => 'skipped', 'message' => 'Not due'];
                continue;
            }

            try {
                $result = $this->runProfile($type);
                $results[$type] = ['ran' => true, 'status' => 'success', 'message' => 'Backup created: ' . $result->id()];
            } catch (GuardianException $e) {
                $this->systemLogger->error(sprintf('Scheduled %s backup failed: %s', $type, $e->getMessage()), 'backup');
                $results[$type] = ['ran' => true, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }
}
