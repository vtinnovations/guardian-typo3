<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

/**
 * Registers Guardian's icons with the TYPO3 IconRegistry. The single module
 * icon is a self-contained SVG shipped in Resources/Public/Icons.
 */
return [
    'guardian-module' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:guardian_typo3/Resources/Public/Icons/module-guardian.svg',
    ],
];
