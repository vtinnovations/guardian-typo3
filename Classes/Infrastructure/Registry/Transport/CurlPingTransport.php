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
 * Native cURL implementation of the one-way invocation signal.
 *
 * One POST, short connect and total deadlines, certificate and host verification
 * fully on, redirects refused, and the answer never read. Every failure — an
 * unreachable endpoint, a timeout, a TLS problem — is swallowed, because a
 * notice that cannot be delivered must not disturb the page being served or the
 * entitlement decision. The handle is always closed.
 */
final class CurlPingTransport implements PingTransportInterface
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
            $options = [
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
            ];
            // Confine the handle to HTTPS where the linked libcurl supports it,
            // so no answer or option can ever downgrade the scheme.
            if (\defined('CURLOPT_PROTOCOLS_STR')) {
                $options[\CURLOPT_PROTOCOLS_STR] = 'https';
            }
            @curl_setopt_array($handle, $options);
            @curl_exec($handle); // the answer is deliberately not read
        } catch (\Throwable) {
            // quiet by design
        } finally {
            @curl_close($handle);
        }
    }
}
