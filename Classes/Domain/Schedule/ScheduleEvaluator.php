<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Schedule;

/**
 * Decides whether a scheduled backup is currently due, and when it will next
 * run. Directly ports the (already CMS-independent) logic from the audited
 * Contao ScheduleEvaluator, restructured around typed value objects and with
 * the reference time passed in explicitly so the class is a pure function of
 * its inputs — no reads of the system clock, no globals, fully testable.
 *
 * Two strategies, chosen by {@see ScheduleFrequency::isIntervalBased()}:
 *   - interval-based (5min/15min/hourly): due when now - lastRun >= interval
 *   - slot-based (daily/weekly/monthly): due when the most recent scheduled
 *     wall-clock occurrence is in the past and newer than the last run
 */
final class ScheduleEvaluator
{
    public function isDue(BackupSchedule $schedule, ScheduleRun $state, \DateTimeImmutable $now): bool
    {
        if (!$schedule->enabled) {
            return false;
        }

        $interval = $schedule->frequency->intervalSeconds();
        if ($interval !== null) {
            if (!$state->hasRun()) {
                return true;
            }

            return ($now->getTimestamp() - $state->lastRun->getTimestamp()) >= $interval;
        }

        $scheduled = $this->mostRecentOccurrence($schedule, $now);
        if ($scheduled === null || $scheduled > $now) {
            return false;
        }

        if (!$state->hasRun()) {
            return true;
        }

        return $state->lastRun < $scheduled;
    }

    public function nextOccurrence(BackupSchedule $schedule, ScheduleRun $state, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if (!$schedule->enabled) {
            return null;
        }

        $interval = $schedule->frequency->intervalSeconds();
        if ($interval !== null) {
            if (!$state->hasRun()) {
                return $now;
            }

            return $state->lastRun->modify('+' . $interval . ' seconds');
        }

        $today = $this->occurrenceForDate($schedule, $now);
        if ($today !== null && $today > $now) {
            return $today;
        }

        return $this->advanceFromMostRecent($schedule, $now);
    }

    private function mostRecentOccurrence(BackupSchedule $schedule, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $today = $this->occurrenceForDate($schedule, $now);
        if ($today !== null && $today <= $now) {
            return $today;
        }

        $cursor = $now->modify('-1 day');
        for ($i = 0; $i < 366; ++$i) {
            $occ = $this->occurrenceForDate($schedule, $cursor);
            if ($occ !== null && $occ <= $now) {
                return $occ;
            }
            $cursor = $cursor->modify('-1 day');

            if ($schedule->frequency === ScheduleFrequency::Monthly && $i > 32) {
                break;
            }
            if ($schedule->frequency === ScheduleFrequency::Weekly && $i > 7) {
                break;
            }
        }

        return null;
    }

    private function advanceFromMostRecent(BackupSchedule $schedule, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $cursor = $now->modify('+1 day');
        for ($i = 0; $i < 366; ++$i) {
            $occ = $this->occurrenceForDate($schedule, $cursor);
            if ($occ !== null && $occ > $now) {
                return $occ;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return null;
    }

    /**
     * If $date is a valid scheduled day for the frequency, returns the moment
     * at the configured HH:MM on that day; otherwise null.
     */
    private function occurrenceForDate(BackupSchedule $schedule, \DateTimeImmutable $date): ?\DateTimeImmutable
    {
        $matches = match ($schedule->frequency) {
            ScheduleFrequency::Daily => true,
            ScheduleFrequency::Weekly => ((int) $date->format('w')) === $schedule->weekday,
            ScheduleFrequency::Monthly => ((int) $date->format('j')) === $schedule->dayOfMonth,
            default => false,
        };

        if (!$matches) {
            return null;
        }

        return $date->setTime($schedule->hour, $schedule->minute, 0);
    }
}
