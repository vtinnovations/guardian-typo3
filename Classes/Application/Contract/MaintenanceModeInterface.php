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

/**
 * Port for toggling a site "maintenance"/locked state around risky operations.
 *
 * The Contao original used `contao:maintenance-mode` with a var/maintenance.html
 * fallback. TYPO3 has no single built-in equivalent; the adapter (a later phase)
 * will implement it, for example via a documented lock file the front controller
 * honours. Failure-safe toggling — always attempting to turn maintenance OFF
 * even after a failed operation — is a requirement captured in the design.
 */
interface MaintenanceModeInterface
{
    /**
     * @throws \Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException when unavailable or on failure.
     */
    public function enable(): void;

    /**
     * @throws \Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException when unavailable or on failure.
     */
    public function disable(): void;

    public function isEnabled(): bool;
}
