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
 * Grants a topic to exactly one caller per signed-in backend session.
 *
 * "Once per session" has to survive a reload, a tab the administrator left open,
 * two tabs opened at the same moment, several application servers behind a load
 * balancer, and the container building a fresh object graph on every request. That
 * rules out a static flag (gone at the end of the request), a value in the browser
 * (chosen by whoever is browsing) and a permanent flag in the database (never
 * released, so a later session would be silent forever).
 *
 * What is left is the framework's own server-side session, which begins at sign-in
 * and ends at sign-out or expiry — exactly the lifetime the claim needs. The claim
 * must be atomic: two requests racing for the same topic must produce one true and
 * one false, never two of either.
 *
 * The mark left behind carries no key, no host, no session identifier and no
 * payload — only that the topic is spoken for.
 */
interface SessionEntryClaimInterface
{
    /**
     * True for the first caller of this topic in the current session, false for
     * every later one and whenever no signed-in session exists.
     *
     * @param string $topic short, stable, non-sensitive; a product slug, or a
     *                      product slug and scope for a scoped package
     */
    public function claim(string $topic): bool;
}
