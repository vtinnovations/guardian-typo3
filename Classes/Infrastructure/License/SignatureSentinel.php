<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\License;

/**
 * Optional second-layer authenticity check: an asymmetric (Ed25519) signature
 * over the canonical license payload.
 *
 * This layer is only meaningful when a distributed, issuer-signed license
 * certificate is used. The existing product already has authoritative online
 * verification, so this check is applied ONLY when both a `signature` field is
 * present in the payload AND a public verification key is embedded in this class.
 * Otherwise it is "not applicable" and passes — it never becomes the sole strong
 * control, and it never blocks the established online model.
 *
 * The private signing key is never present in the distributed package: only the
 * public key is embedded here (split, base64). The signature covers a
 * deterministic, canonical JSON of the payload with the `signature` field itself
 * excluded.
 */
final class SignatureSentinel
{
    /**
     * @param array<string, mixed> $payload decoded license store
     */
    public function verified(array $payload): bool
    {
        $signature = $payload['signature'] ?? null;
        if (!\is_string($signature) || $signature === '') {
            return true; // no signature present → not applicable
        }
        $publicKey = $this->publicKey();
        if ($publicKey === '' || !\function_exists('sodium_crypto_sign_verify_detached')) {
            return true; // no embedded key / no libsodium → cannot apply this layer
        }
        $rawSignature = base64_decode($signature, true);
        if ($rawSignature === false || \strlen($rawSignature) !== \SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($rawSignature, $this->canonical($payload), $publicKey);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether a certificate signature is actually in play (present + verifiable
     * configuration), so callers can distinguish "valid" from "not applicable".
     *
     * @param array<string, mixed> $payload
     */
    public function applies(array $payload): bool
    {
        return \is_string($payload['signature'] ?? null)
            && ($payload['signature'] ?? '') !== ''
            && $this->publicKey() !== ''
            && \function_exists('sodium_crypto_sign_verify_detached');
    }

    /**
     * Deterministic canonical JSON of the payload, excluding the signature.
     *
     * @param array<string, mixed> $payload
     */
    private function canonical(array $payload): string
    {
        unset($payload['signature']);
        $this->recursiveKeySort($payload);
        $json = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return $json === false ? '' : $json;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function recursiveKeySort(array &$data): void
    {
        foreach ($data as &$value) {
            if (\is_array($value)) {
                $this->recursiveKeySort($value);
            }
        }
        unset($value);
        if (!array_is_list($data)) {
            ksort($data);
        }
    }

    /** Embedded Ed25519 public key (split, base64). Empty until a release pins one. */
    private function publicKey(): string
    {
        $b64 = $this->pkA() . $this->pkB();
        if ($b64 === '') {
            return '';
        }
        $raw = base64_decode($b64, true);
        if ($raw === false) {
            return '';
        }
        $expectedLength = \defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES') ? \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES : 32;

        return \strlen($raw) === $expectedLength ? $raw : '';
    }

    private function pkA(): string
    {
        return '';
    }

    private function pkB(): string
    {
        return '';
    }
}
