<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

defined('TYPO3') || die();

/*
 * Registers this product with the shared V-T.ONE licence screen.
 *
 * The registry is a plain configuration array so that every V-T.ONE extension can
 * add itself without depending on any of the others, and so that the screen lists
 * exactly what is installed. The slug is unique per product, so two extensions
 * cannot overwrite one another's entry.
 *
 * Only a name and the class that supplies the section are declared here. The
 * section's state is read at request time from the container, never cached in
 * configuration.
 */
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['vtone']['packages'][\Vtinnovations\GuardianTypo3\Domain\Configuration\ServiceRecord::PROJECT_SLUG] = [
    'title' => \Vtinnovations\GuardianTypo3\Domain\Configuration\ServiceRecord::PROJECT,
    'provider' => \Vtinnovations\GuardianTypo3\Typo3\Backend\GuardianPackageSection::class,
];
