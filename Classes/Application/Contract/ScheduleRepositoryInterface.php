<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Contract;

use Vtinnovations\GuardianTypo3\Domain\Schedule\BackupSchedule;
use Vtinnovations\GuardianTypo3\Domain\Schedule\ScheduleRun;

/**
 * Read port for scheduled-backup configuration and last-run state.
 *
 * Phase 1 exposes only reads so the dashboard can display the (currently
 * inert) schedule and compute a forecast. Actually running scheduled backups
 * is a later phase.
 */
interface ScheduleRepositoryInterface
{
    /**
     * @param 'mini'|'full' $slot
     */
    public function loadSchedule(string $slot): BackupSchedule;

    /**
     * @param 'mini'|'full' $slot
     */
    public function loadState(string $slot): ScheduleRun;
}
