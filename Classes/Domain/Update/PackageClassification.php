<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Update;

/**
 * Language-neutral classification of an installed Composer package for the
 * Dashboard package manager: TYPO3 core (shipped as part of the CMS and unsafe to
 * touch individually), a local/custom package (a path-repository package the
 * installation owns), or a third-party dependency.
 */
enum PackageClassification: string
{
    case Core = 'core';
    case Custom = 'custom';
    case ThirdParty = 'third_party';

    /**
     * @param bool $isPathRepository whether the package resolves to a local path repository
     */
    public static function classify(string $name, string $type, bool $isPathRepository): self
    {
        if (self::isCore($name, $type)) {
            return self::Core;
        }
        if ($isPathRepository) {
            return self::Custom;
        }

        return self::ThirdParty;
    }

    public static function isCore(string $name, string $type): bool
    {
        $name = strtolower($name);

        return $name === 'typo3/cms'
            || str_starts_with($name, 'typo3/cms-')
            || $type === 'typo3-cms-framework';
    }
}
