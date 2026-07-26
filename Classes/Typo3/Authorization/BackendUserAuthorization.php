<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Typo3\Authorization;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Vtinnovations\GuardianTypo3\Application\Contract\BackendAuthorizationInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * TYPO3 adapter for {@see BackendAuthorizationInterface}.
 *
 * Every Guardian operation is administrator-only. The backend module itself is
 * already registered with `access => 'admin'`, but this adapter provides a
 * second, in-code gate so application logic can assert admin rights independently
 * of the module routing (defence in depth, mirroring the audited Contao
 * BackendAuthChecker). It is the only place Guardian reads the backend user.
 */
final class BackendUserAuthorization implements BackendAuthorizationInterface
{
    public function isAdministrator(): bool
    {
        $user = $this->backendUser();

        return $user !== null && $user->isAdmin();
    }

    public function assertAdministrator(): void
    {
        if (!$this->isAdministrator()) {
            throw new GuardianException('Guardian actions require a TYPO3 backend administrator.');
        }
    }

    public function isSystemMaintainer(): bool
    {
        $user = $this->backendUser();
        if ($user === null || !$user->isAdmin()) {
            return false;
        }
        $maintainers = $GLOBALS['TYPO3_CONF_VARS']['SYS']['systemMaintainers'] ?? [];
        $maintainers = \is_array($maintainers) ? array_map('intval', $maintainers) : [];
        if ($maintainers === []) {
            // No explicit maintainer list configured → any admin qualifies.
            return true;
        }

        return \in_array((int) ($user->user['uid'] ?? 0), $maintainers, true);
    }

    public function currentUserIdentifier(): ?string
    {
        $user = $this->backendUser();
        if ($user === null) {
            return null;
        }

        $username = (string) ($user->user['username'] ?? '');

        return $username !== '' ? $username : null;
    }

    private function backendUser(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $user : null;
    }
}
