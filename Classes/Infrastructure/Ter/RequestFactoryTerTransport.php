<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Ter;

use TYPO3\CMS\Core\Http\RequestFactory;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * The production transport for the TYPO3 Extension Repository AND the Packagist
 * metadata endpoints used to resolve Composer package identities. Every request
 * is a plain GET with:
 *   - strict TLS peer + host verification ('verify' => true)
 *   - NO redirects (a moved/hijacked endpoint must fail, not follow)
 *   - bounded connect + total timeouts
 *   - a hard response-size cap (streamed, never buffered unbounded)
 *
 * The host is asserted against a FIXED allowlist here as defence in depth; the
 * callers only ever build URLs from constants + validated tokens. Transport
 * failures are classified into precise machine codes (DNS / TLS / timeout /
 * unreachable) so the UI can tell "not found" apart from "could not connect".
 */
final class RequestFactoryTerTransport implements TerHttpTransportInterface
{
    private const ALLOWED_HOSTS = ['extensions.typo3.org', 'packagist.org', 'repo.packagist.org'];
    private const MAX_BODY_BYTES = 6 * 1024 * 1024;

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {
    }

    /**
     * @return array{status: int, body: string}
     */
    public function get(string $url): array
    {
        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));
        if ($scheme !== 'https' || !\in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new GuardianException('untrusted_endpoint');
        }

        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'headers' => ['Accept' => 'application/json', 'User-Agent' => 'Guardian-TYPO3'],
                'allow_redirects' => false,
                'connect_timeout' => 4,
                'timeout' => 8,
                'verify' => true,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new GuardianException($this->classify($e));
        }

        $body = $response->getBody();
        $content = '';
        try {
            while (!$body->eof() && \strlen($content) < self::MAX_BODY_BYTES) {
                $chunk = $body->read(65536);
                if ($chunk === '') {
                    break;
                }
                $content .= $chunk;
            }
        } catch (\Throwable $e) {
            throw new GuardianException($this->classify($e));
        }

        return ['status' => $response->getStatusCode(), 'body' => $content];
    }

    /**
     * Classify a transport throwable into a precise, credential-free code. The
     * message is inspected across the exception chain (Guzzle wraps the cURL
     * message), never exposing URLs or upstream bodies.
     */
    private function classify(\Throwable $e): string
    {
        $message = strtolower($e->getMessage());
        for ($cur = $e->getPrevious(); $cur !== null; $cur = $cur->getPrevious()) {
            $message .= ' ' . strtolower($cur->getMessage());
        }

        if (str_contains($message, 'could not resolve host') || str_contains($message, 'name resolution') || str_contains($message, 'getaddrinfo') || str_contains($message, 'name or service not known')) {
            return 'dns_failure';
        }
        if (str_contains($message, 'ssl') || str_contains($message, 'certificate') || str_contains($message, 'tls') || str_contains($message, 'handshake')) {
            return 'tls_failure';
        }
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'timeout';
        }
        if (str_contains($message, 'could not connect') || str_contains($message, 'connection refused') || str_contains($message, 'failed to connect') || str_contains($message, 'network is unreachable')) {
            return 'service_unreachable';
        }

        return 'transport_error';
    }
}
