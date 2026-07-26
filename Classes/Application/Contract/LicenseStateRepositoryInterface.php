<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Contract;

use Vtinnovations\GuardianTypo3\Domain\License\LicenseState;

/**
 * Persistence port for the locally cached verification result.
 */
interface LicenseStateRepositoryInterface
{
    public function load(): LicenseState;

    public function save(LicenseState $state): void;

    public function clear(): void;
}
