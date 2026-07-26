<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\License\Signal;

/**
 * Minimal one-way transport for the invocation signal. Isolated behind an
 * interface so the network layer can be substituted in tests without contacting
 * any live endpoint. Implementations MUST be silent and side-effect-free beyond
 * the outbound request, and MUST NOT read or return the response body.
 */
interface SignalTransportInterface
{
    public function send(string $url, string $jsonBody): void;
}
