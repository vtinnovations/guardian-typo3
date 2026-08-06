<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Support;

use Vtinnovations\GuardianTypo3\Application\Contract\SessionEntryClaimInterface;

/**
 * A backend session, without TYPO3: the first caller of a topic wins, every later
 * one loses, and signing in again starts over.
 */
final class InMemorySessionClaim implements SessionEntryClaimInterface
{
    /** @var array<string, true> */
    private array $taken = [];

    /** @var list<string> every topic that was ever granted, in order */
    public array $granted = [];

    public function __construct(
        private bool $signedIn = true,
    ) {
    }

    public function claim(string $topic): bool
    {
        if (!$this->signedIn || isset($this->taken[$topic])) {
            return false;
        }
        $this->taken[$topic] = true;
        $this->granted[] = $topic;

        return true;
    }

    /** A new sign-in: the session is fresh, so a topic may be claimed again. */
    public function newSession(): void
    {
        $this->taken = [];
    }

    public function signOut(): void
    {
        $this->signedIn = false;
    }
}
