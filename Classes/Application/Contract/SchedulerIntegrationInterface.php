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

/**
 * Port describing how Guardian is driven by the host's periodic task runner.
 *
 * The Contao original used a `#[AsCronJob('minutely')]` hook executed in
 * kernel.terminate. TYPO3's equivalent is the Scheduler extension (a CLI task
 * run from the system crontab), or a console command wired into the system
 * crontab directly. This port lets the scheduled-backup evaluator stay unaware
 * of which mechanism ultimately calls it.
 */
interface SchedulerIntegrationInterface
{
    /**
     * Whether a periodic runner appears to be configured/active for Guardian.
     */
    public function isActive(): bool;

    /**
     * Human-readable description of the detected scheduling mechanism, for the
     * backend to display (e.g. "TYPO3 Scheduler task", "system crontab", "none").
     */
    public function describe(): string;
}
