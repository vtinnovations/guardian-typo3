<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Schedule;

use Vtinnovations\GuardianTypo3\Domain\Schedule\BackupSchedule;
use Vtinnovations\GuardianTypo3\Domain\Schedule\ScheduleRun;

/**
 * Immutable read model describing the current standing of one schedule slot:
 * its configuration, its last run, whether it is due now, and when it will next
 * run. Purely informational — used by the dashboard.
 */
final class ScheduleForecast
{
    public function __construct(
        public readonly string $slot,
        public readonly BackupSchedule $schedule,
        public readonly ScheduleRun $lastRun,
        public readonly bool $due,
        public readonly ?\DateTimeImmutable $nextRun,
    ) {
    }
}
