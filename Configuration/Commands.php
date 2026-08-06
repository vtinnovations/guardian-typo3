<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

use Vtinnovations\GuardianTypo3\Command\ReleaseCheckCommand;
use Vtinnovations\GuardianTypo3\Command\RunDueBackupsCommand;
use Vtinnovations\GuardianTypo3\Command\RunUpdateJobCommand;

/**
 * TYPO3 console command registration (identical API on 13.4 and 14).
 */
return [
    'guardian:backup:run-due' => [
        'class' => RunDueBackupsCommand::class,
    ],
    'guardian:update:run' => [
        'class' => RunUpdateJobCommand::class,
    ],
    'guardian:release:check' => [
        'class' => ReleaseCheckCommand::class,
    ],
];
