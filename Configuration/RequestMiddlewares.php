<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

use Vtinnovations\GuardianTypo3\Middleware\MaintenanceMiddleware;

/**
 * Registers the frontend maintenance middleware (identical API on TYPO3 13.4/14).
 * It only runs in the frontend stack, so the backend and CLI are never blocked.
 */
return [
    'frontend' => [
        'vtinnovations/guardian-typo3/maintenance' => [
            'target' => MaintenanceMiddleware::class,
            'before' => [
                'typo3/cms-frontend/prepare-tsfe-rendering',
            ],
            'after' => [
                'typo3/cms-frontend/site',
            ],
        ],
    ],
];
