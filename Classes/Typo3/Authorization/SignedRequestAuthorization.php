<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Typo3\Authorization;

use Psr\Http\Message\ServerRequestInterface;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\CanonicalForm;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\DetachedSignature;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\SigningKey;

/**
 * Decides whether an inbound server-to-server request really came from the
 * vendor.
 *
 * This sits beside {@see BackendUserAuthorization} because it answers the same
 * kind of question for a different kind of caller. A backend user is
 * authenticated by their session and a route token; a machine is authenticated by
 * a signature, and by nothing else. A claimed web origin proves nothing here:
 * `Origin`, `Referer`, the user agent, the source address and a reverse lookup
 * are all attacker-controlled or spoofable, so none of them takes part in this
 * decision. An operator may still add mutual TLS or a network allow-list in front
 * of the endpoint; that is defence in depth, never a substitute.
 *
 * The signature covers the verb, the exact path, the identifier, the time, the
 * one-use value and a digest of the raw body — so it cannot be lifted onto
 * another endpoint, another method or another body. It follows the issuer's
 * published `vt-one/request-sig-v1` rule, in which the key id selects the key
 * but is not itself signed. The signed header
 * values are then required to equal the duplicated fields inside the body, so the
 * two halves of the request cannot disagree.
 *
 * Every refusal looks identical to the caller.
 */
final class SignedRequestAuthorization
{
    public const HEADER_REQUEST_ID = 'X-VT-Request-ID';
    public const HEADER_TIMESTAMP = 'X-VT-Timestamp';
    public const HEADER_NONCE = 'X-VT-Nonce';
    public const HEADER_KEY_ID = 'X-VT-Key-ID';
    public const HEADER_SIGNATURE = 'X-VT-Signature';

    /** How far apart the two clocks may be before the request is stale. */
    public const MAX_SKEW_SECONDS = 300;

    /** Only algorithms the release ring pins are ever attempted. */
    private const ALGORITHM = SigningKey::ALGORITHM_ED25519;

    public function __construct(
        private readonly CanonicalForm $canonical,
        private readonly DetachedSignature $signature,
    ) {
    }

    /**
     * @param array<string, mixed> $body decoded request body
     */
    public function authenticate(
        ServerRequestInterface $request,
        string $rawBody,
        array $body,
        string $expectedPath,
        int $now,
    ): SignedRequestIdentity {
        $requestId = trim($request->getHeaderLine(self::HEADER_REQUEST_ID));
        $timestampHeader = trim($request->getHeaderLine(self::HEADER_TIMESTAMP));
        $nonce = trim($request->getHeaderLine(self::HEADER_NONCE));
        $keyId = trim($request->getHeaderLine(self::HEADER_KEY_ID));
        $signature = trim($request->getHeaderLine(self::HEADER_SIGNATURE));

        if ($requestId === '' || $timestampHeader === '' || $nonce === '' || $keyId === '' || $signature === '') {
            return SignedRequestIdentity::rejected('authentication_incomplete');
        }
        if (strlen($requestId) > 128 || strlen($nonce) > 256 || preg_match('/^-?\d{1,19}$/', $timestampHeader) !== 1) {
            return SignedRequestIdentity::rejected('authentication_incomplete');
        }

        $timestamp = (int) $timestampHeader;
        if ($timestamp <= 0 || abs($now - $timestamp) > self::MAX_SKEW_SECONDS) {
            return SignedRequestIdentity::rejected('stale_request');
        }

        // The signed headers and the duplicated body fields must agree, or the
        // request is internally inconsistent and is refused without further work.
        if (!$this->agrees($body, 'request_id', $requestId)
            || !$this->agrees($body, 'nonce', $nonce)
            || ($body['timestamp'] ?? null) !== $timestamp
        ) {
            return SignedRequestIdentity::rejected('metadata_mismatch');
        }

        // The key id selects which key to try; it is not part of what the issuer signed.
        $message = $this->canonical->request(
            $request->getMethod(),
            $expectedPath,
            $requestId,
            $timestamp,
            $nonce,
            hash('sha256', $rawBody),
        );

        if (!$this->signature->isAuthentic($message, $signature, $keyId, self::ALGORITHM, SigningKey::PURPOSE_REQUEST, $now)) {
            return SignedRequestIdentity::rejected($this->signature->refusalCategory($keyId));
        }

        return SignedRequestIdentity::accepted($requestId, $nonce, $keyId, $timestamp);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function agrees(array $body, string $field, string $expected): bool
    {
        $value = $body[$field] ?? null;

        return \is_string($value) && hash_equals($expected, $value);
    }
}
