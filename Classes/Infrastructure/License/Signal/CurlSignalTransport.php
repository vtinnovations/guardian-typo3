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
 * Native cURL implementation of the one-way invocation transport.
 *
 * It performs a single POST with short connect/total timeouts, TLS verification
 * fully enabled, no redirects, and never inspects or returns the response body.
 * Any failure (unavailable endpoint, timeout, TLS problem) is swallowed silently
 * so it can never disturb the request or the license decision. The handle is
 * always closed.
 */
final class CurlSignalTransport implements SignalTransportInterface
{
    public function send(string $url, string $jsonBody): void
    {
        if (!\function_exists('curl_init')) {
            return;
        }
        $handle = @curl_init();
        if ($handle === false) {
            return;
        }
        try {
            @curl_setopt_array($handle, [
                \CURLOPT_URL => $url,
                \CURLOPT_POST => true,
                \CURLOPT_POSTFIELDS => $jsonBody,
                \CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_FOLLOWLOCATION => false,
                \CURLOPT_MAXREDIRS => 0,
                \CURLOPT_CONNECTTIMEOUT_MS => 1500,
                \CURLOPT_TIMEOUT_MS => 2500,
                \CURLOPT_NOSIGNAL => true,
                \CURLOPT_SSL_VERIFYPEER => true,
                \CURLOPT_SSL_VERIFYHOST => 2,
                \CURLOPT_FORBID_REUSE => true,
            ]);
            @curl_exec($handle); // response body is intentionally ignored
        } catch (\Throwable) {
            // fail safely and quietly
        } finally {
            @curl_close($handle);
        }
    }
}
