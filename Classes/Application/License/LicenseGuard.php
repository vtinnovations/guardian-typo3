<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\License;

use Vtinnovations\GuardianTypo3\Domain\License\LicenseTier;

final class LicenseGuard
{
    public function __construct(private readonly LicenseManager $manager)
    {
    }

    public function isLicensed(): bool
    {
        return $this->manager->currentStatus()->licensed;
    }

    public function isPro(): bool
    {
        return $this->manager->currentStatus()->pro;
    }

    public function allows(LicenseTier $required): bool
    {
        return $this->manager->currentStatus()->tier()->isAtLeast($required);
    }
}
