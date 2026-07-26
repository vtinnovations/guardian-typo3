<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Typo3\Scheduler;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use Vtinnovations\GuardianTypo3\Application\Contract\SchedulerIntegrationInterface;

/**
 * TYPO3 adapter for {@see SchedulerIntegrationInterface}.
 *
 * Reports (read-only) which periodic-task mechanism is available. In Phase 1
 * Guardian registers NO scheduled task and drives NO backups, so
 * {@see self::isActive()} is always false; the description tells the admin what
 * the eventual options are (TYPO3 Scheduler task vs. a console command in the
 * system crontab). Wiring an actual task is a later phase.
 */
final class Typo3SchedulerIntegration implements SchedulerIntegrationInterface
{
    public function isActive(): bool
    {
        // No scheduled Guardian task exists yet in this phase.
        return false;
    }

    public function describe(): string
    {
        if (ExtensionManagementUtility::isLoaded('scheduler')) {
            return 'TYPO3 Scheduler is installed. Guardian scheduled backups are not wired to it yet (planned).';
        }

        return 'No scheduler detected. Scheduled backups will require the TYPO3 Scheduler '
            . 'or a system crontab entry (planned for a later phase).';
    }
}
