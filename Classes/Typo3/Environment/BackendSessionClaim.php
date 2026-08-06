<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Typo3\Environment;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Vtinnovations\GuardianTypo3\Application\Contract\LockFactoryInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\SessionEntryClaimInterface;

/**
 * TYPO3 adapter that claims a topic once per signed-in backend session.
 *
 * The mark lives in the backend user's server-side session data, so it is created
 * by signing in and disposed of by signing out, by the session expiring or by
 * signing in again — no extra lifetime to manage and nothing left behind. It is
 * never written to a cookie, to browser storage or to a permanent flag, and the
 * value stored is the bare fact that the topic is taken.
 *
 * Reading the session and writing it back is not one operation in TYPO3, so two
 * tabs opened together could otherwise both read "not yet claimed". A short
 * file lock closes that window: the read, the decision and the write happen inside
 * it, and a request that cannot take the lock reports the topic as already claimed
 * rather than risk a second one. Being silent is always the safe answer here.
 *
 * The lock name is a digest, so no session identifier ever appears as a file name.
 */
final class BackendSessionClaim implements SessionEntryClaimInterface
{
    /** Namespaced so nothing else in the session can collide with it. */
    private const PREFIX = 'guardian.entry.';

    /** Long enough to cover a read/write pair, short enough never to hold a page. */
    private const LOCK_SECONDS = 5;

    public function __construct(
        private readonly LockFactoryInterface $lockFactory,
    ) {
    }

    public function claim(string $topic): bool
    {
        $topic = trim($topic);
        if ($topic === '' || preg_match('/^[a-z0-9._-]{1,64}$/', $topic) !== 1) {
            return false;
        }

        $user = $this->backendUser();
        if ($user === null) {
            return false; // no signed-in session, nothing to claim within
        }

        $key = self::PREFIX . $topic;
        // A cheap look before the lock: once the topic is taken — which is the
        // usual case after the first page view — no lock is needed at all.
        if ($user->getSessionData($key) !== null) {
            return false;
        }

        try {
            $lock = $this->lockFactory->create('entry-' . substr(hash('sha256', $key), 0, 24), self::LOCK_SECONDS);
        } catch (\Throwable) {
            // No lock means no way to be sure this is the first caller, and being
            // silent is always the safe answer.
            return false;
        }
        if (!$lock->acquire()) {
            return false;
        }

        try {
            // Read again inside the lock: the other tab may have claimed it in
            // the moment between the look above and the lock being taken.
            if ($user->getSessionData($key) !== null) {
                return false;
            }
            $user->setAndSaveSessionData($key, true);

            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            $lock->release();
        }
    }

    private function backendUser(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            return null;
        }

        // An anonymous or half-built user object is not a session.
        return (int) ($user->user['uid'] ?? 0) > 0 ? $user : null;
    }
}
