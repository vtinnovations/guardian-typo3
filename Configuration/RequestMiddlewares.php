<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

use Vtinnovations\GuardianTypo3\Middleware\RestEndpointMiddleware;
use Vtinnovations\GuardianTypo3\Middleware\MaintenanceMiddleware;

/**
 * Registers Guardian's frontend middlewares (identical API on TYPO3 13.4/14).
 * Both run only in the frontend stack, so the backend and CLI are never blocked.
 *
 * The machine-facing REST middleware is placed as early as the request URL is
 * reliably normalized (after the normalized-params attribute) and BEFORE site
 * resolution, so its single fixed public endpoint is reachable independently of
 * the site configuration, the page tree and page slugs — and cannot be shadowed
 * by a 404 from frontend routing. It matches one exact path and passes every
 * other request straight through.
 */
return [
    'frontend' => [
        'vtinnovations/guardian-typo3/rest-endpoint' => [
            'target' => RestEndpointMiddleware::class,
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
            'before' => [
                'typo3/cms-frontend/site',
            ],
        ],
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
