<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\License;

enum LicenseValidationStatus: string
{
    case None = 'none';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Unreachable = 'unreachable';
}
