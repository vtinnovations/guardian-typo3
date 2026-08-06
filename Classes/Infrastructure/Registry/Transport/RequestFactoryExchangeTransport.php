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
use TYPO3\CMS\Core\Http\RequestFactory;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\ServiceEndpoint;

/**
 * The production transport, built on TYPO3's own HTTP client so the
 * installation's proxy and CA configuration apply.
 *
 * Every option that could weaken the call is pinned here rather than left to a
 * default: certificate and host-name verification stay on, redirects are refused
 * instead of followed — so a 3xx cannot move the request to another host, scheme
 * or port — and both deadlines are enforced. An HTTP error status is returned
 * rather than thrown, because deciding what a status means is the protocol's job.
 */
final class RequestFactoryExchangeTransport implements ExchangeTransportInterface
{
    public function __construct(private readonly RequestFactory $requestFactory)
    {
    }

    public function post(string $url, array $packet, float $connectTimeout, float $totalTimeout): ResponseInterface
    {
        return $this->requestFactory->request($url, 'POST', [
            'headers' => [
                'Accept' => ServiceEndpoint::MEDIA_TYPE,
                'Content-Type' => ServiceEndpoint::MEDIA_TYPE,
            ],
            'json' => $packet,
            'connect_timeout' => $connectTimeout,
            'timeout' => $totalTimeout,
            'allow_redirects' => false,
            'http_errors' => false,
            'verify' => true,
        ]);
    }
}
