<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Configuration;

/**
 * The vendor's own assessment carried inside a service record document.
 */
enum RecordStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
}
