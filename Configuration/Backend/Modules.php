<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

use Vtinnovations\GuardianTypo3\Controller\Backend\GuardianModuleController;
use Vtinnovations\GuardianTypo3\Controller\Backend\PackageOverviewController;

/**
 * Backend module registration, compatible with TYPO3 13.4.9+ and TYPO3 14.
 *
 * The array-based module registration API, the `system` parent group, the
 * `access` gate, `routes/_default/target`, `iconIdentifier`, `path` and `labels`
 * keys used here are all identical in TYPO3 13.4 and 14 — one shared file works
 * for both, with no version branching.
 *
 * Guardian lives under the "System" area — the natural home for install-,
 * maintenance- and administration-level tooling. It is a single module whose
 * internal sections (Dashboard, Updates, Backup, Recovery, Schedule, Settings,
 * License) are selected via an `action` query parameter handled by the
 * controller; there are no destructive submodules.
 *
 * `access => 'admin'` restricts the module to TYPO3 administrators natively —
 * this is the primary authorization gate, reinforced in the controller.
 *
 * `position` intentionally uses the version-neutral `bottom` hint rather than an
 * `after`/`before` sibling identifier: core system-module identifiers are not
 * guaranteed to be identical across TYPO3 13.4 and 14, so anchoring to one would
 * couple placement to a specific release.
 *
 * The second entry is the shared V-T.ONE licence screen. Its identifier is
 * deliberately generic and identical in every V-T.ONE extension: TYPO3 merges
 * module registrations by identifier, so several installed V-T.ONE products
 * produce one entry that lists them all, rather than one competing entry each.
 * Which products it lists comes from the registry, not from this file.
 */
return [
    'vtone_licensing' => [
        'parent' => 'system',
        'position' => ['bottom'],
        'access' => 'admin',
        'iconIdentifier' => 'guardian-module',
        'path' => '/module/system/vtone-licensing',
        'labels' => 'LLL:EXT:guardian_typo3/Resources/Private/Language/locallang_vtone.xlf',
        'routes' => [
            '_default' => [
                'target' => PackageOverviewController::class . '::handleRequest',
            ],
        ],
    ],
    'guardian' => [
        'parent' => 'system',
        'position' => ['bottom'],
        'access' => 'admin',
        'iconIdentifier' => 'guardian-module',
        'path' => '/module/system/guardian',
        'labels' => 'LLL:EXT:guardian_typo3/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => GuardianModuleController::class . '::handleRequest',
            ],
        ],
    ],
];
