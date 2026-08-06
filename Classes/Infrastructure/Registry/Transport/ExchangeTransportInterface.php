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

use Psr\Http\Message\ResponseInterface;

/**
 * The HTTPS call that carries an entitlement exchange.
 *
 * It is separated from the protocol logic for the same reason the TER transport
 * is: the rules about what to send and how to judge the answer belong with the
 * protocol, while the mechanics of making the call belong here — and a test must
 * be able to substitute the mechanics without any live endpoint being involved.
 *
 * An implementation posts a JSON body to the exact URL it is given and nowhere
 * else. It must refuse redirects, verify the certificate and the host name, and
 * apply the deadlines it is given. It must not throw for an HTTP error status;
 * the caller decides what a status means.
 *
 * @throws \Throwable when the call could not be completed at all
 */
interface ExchangeTransportInterface
{
    /**
     * @param array<string, mixed> $packet the JSON body to send
     */
    public function post(string $url, array $packet, float $connectTimeout, float $totalTimeout): ResponseInterface;
}
