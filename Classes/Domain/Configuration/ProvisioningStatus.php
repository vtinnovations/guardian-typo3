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
 * The four possible results of an outbound exchange. Only Confirmed may change
 * stored state; Denied follows the documented withdrawal policy; Unreachable and
 * Rejected always preserve whatever was already stored.
 */
enum ProvisioningStatus: string
{
    case Confirmed = 'confirmed';
    case Denied = 'denied';
    case Unreachable = 'unreachable';
    case Rejected = 'rejected';
}
