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

use Psr\Http\Message\ServerRequestInterface;

/**
 * Answers "which host is this installation?".
 *
 * During a web request the answer comes from data the framework has already
 * validated against the installation's trusted-host configuration, so a forged
 * Host or forwarding header cannot be used to claim a different identity. Outside
 * a web request — a console command, a queue worker, the scheduler — there is no
 * request to ask, and the answer is the host this installation was last observed
 * to serve. An installation that has never been observed has no identity, and the
 * caller must treat that as "cannot establish entitlement" rather than as
 * permission.
 */
interface InstallationIdentityInterface
{
    /** The canonical host, or an empty string when it cannot be established. */
    public function current(): string;

    /** Whether the answer came from live request data rather than from memory. */
    public function isLive(): bool;

    /**
     * The canonical host a specific request is addressing, or an empty string
     * when it fails the installation's trusted-host policy. Used where the
     * request being handled is not (yet) the framework's ambient one.
     */
    public function resolveFrom(ServerRequestInterface $request): string;
}
