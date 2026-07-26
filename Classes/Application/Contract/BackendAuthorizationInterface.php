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
 * Guardian's authorization port.
 *
 * Every Guardian action is administrator-only. In the Contao original this was
 * a Symfony-Security ROLE_ADMIN check bolted onto controllers; here it is an
 * explicit port so core logic never reaches into TYPO3's backend-user globals.
 * The TYPO3 adapter implements it against the backend user; tests fake it.
 */
interface BackendAuthorizationInterface
{
    /**
     * @return bool True when a backend user is authenticated AND is a TYPO3 admin.
     */
    public function isAdministrator(): bool;

    /**
     * Whether the current backend user is a TYPO3 system maintainer (a stricter
     * role than administrator, required for Guardian self-maintenance). When no
     * system maintainers are configured, any administrator qualifies.
     */
    public function isSystemMaintainer(): bool;

    /**
     * @throws \Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException when the current user is not an admin.
     */
    public function assertAdministrator(): void;

    /**
     * Stable identifier of the current backend user for audit logging, or null
     * when unauthenticated.
     */
    public function currentUserIdentifier(): ?string;
}
