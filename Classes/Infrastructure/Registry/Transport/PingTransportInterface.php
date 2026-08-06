<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Registry\Transport;

/**
 * Minimal one-way transport for the invocation signal.
 *
 * It is an interface purely so tests can observe the call without any live
 * endpoint being contacted. An implementation must stay silent, must not read or
 * return the response, and must not raise — the signal is an operational notice,
 * never an input to any decision.
 */
interface PingTransportInterface
{
    public function send(string $url, string $jsonBody): void;
}
