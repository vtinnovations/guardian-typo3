<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Support;

/**
 * The issuer's side of the protocol, transcribed from its own source.
 *
 * Everything Guardian signs is verified elsewhere in this suite by Guardian's own code, which
 * proves it is self-consistent and nothing more. A client can agree with itself perfectly and still
 * reject every genuine licence, because the question that matters is whether it agrees with the
 * *issuer* — and only the issuer's rules answer that.
 *
 * So this class does not call Guardian's helpers at all. It reproduces the issuer's published rules
 * directly:
 *
 *   - `vt-one/canonical-json-v1` — `unset($payload['signature'])`, recursive `ksort` on objects,
 *     lists left alone, `json_encode` with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
 *   - `vt-one/request-sig-v1` — six values joined with `\n`: uppercased method, exact path,
 *     request id, timestamp as a decimal string, one-use value, lowercase hex SHA-256 of the raw
 *     body. No key id.
 *   - the licence document serialized once, with the Base64 payload and the MD5 both taken from
 *     those same bytes.
 *   - the record signed without naming a key; the envelope and the push request naming one.
 *
 * If Guardian's canonicalisation drifts from the issuer's by so much as a byte, the packets this
 * produces stop verifying and the tests using it fail — which is the point. The key pair is
 * generated per instance and is throwaway; no issuer key material is involved.
 */
final class IssuerSimulator
{
    private readonly string $secretKey;
    public readonly string $publicKeyBase64;

    public function __construct(
        public readonly string $keyId = 'vtone-2026a',
        public readonly string $algorithm = 'ed25519',
    ) {
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKeyBase64 = base64_encode(sodium_crypto_sign_publickey($pair));
    }

    // ── The issuer's canonicalisation, transcribed ───────────────────────────

    /**
     * `vt-one/canonical-json-v1`.
     *
     * @param array<string, mixed> $payload
     */
    public function canonicalJson(array $payload): string
    {
        unset($payload['signature']);

        return (string) json_encode($this->sortObjects($payload), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /** `vt-one/request-sig-v1`. Note the absence of the key id. */
    public function requestSigningString(
        string $method,
        string $path,
        string $requestId,
        int $timestamp,
        string $nonce,
        string $body,
    ): string {
        return implode("\n", [
            strtoupper($method),
            $path,
            $requestId,
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    private function sortObjects(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortObjects($item), $value);
        }
        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->sortObjects($item), $value);
    }

    // ── Packets ─────────────────────────────────────────────────────────────

    /**
     * A complete signed package as the issuer's verify endpoint would return it.
     *
     * @param array<string, mixed> $overrides
     * @return array{payload: string, envelope: array<string, mixed>, bytes: string, document: array<string, mixed>}
     */
    public function package(array $overrides = [], int $generatedAt = 1784880547): array
    {
        $document = $this->document($overrides);

        // Serialized exactly once. The Base64 and the MD5 both come from these bytes.
        $bytes = (string) json_encode($this->sortObjects($document), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return [
            'payload' => base64_encode($bytes),
            'envelope' => $this->envelope($bytes, (int) $document['license_version'], $generatedAt),
            'bytes' => $bytes,
            'document' => $document,
        ];
    }

    /**
     * The record document, signed without naming a key.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function document(array $overrides = []): array
    {
        $fields = $overrides + [
            'schema_version' => 2,
            'project' => 'Guardian',
            'project_slug' => 'guardian',
            'license_key' => 'GRD-TEST-0001-0002-0003',
            'license_domain' => 'example.com',
            'license_package' => 'pro',
            'license_features' => [],
            'license_version' => 7,
            'license_issued_at' => 1784000000,
            'license_starts_at' => 1784000000,
            'license_expires_at' => 1815536000,
            'license_lifetime' => false,
            'license_verified_at' => 1784880547,
            'free_available' => true,
            'validation_status' => 'valid',
        ];

        // A perpetual record carries no expiry at all, which is what the issuer enforces before
        // signing.
        if (($fields['license_lifetime'] ?? false) === true && !\array_key_exists('license_expires_at', $overrides)) {
            $fields['license_expires_at'] = null;
        }

        $fields['signature'] = $this->sign($this->canonicalJson($fields));

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    public function envelope(string $bytes, int $version, int $generatedAt = 1784880547): array
    {
        $envelope = [
            'project' => 'Guardian',
            'project_slug' => 'guardian',
            'license_version' => $version,
            'license_md5' => md5($bytes),
            'generated_at' => $generatedAt,
            'key_id' => $this->keyId,
            'signature_algorithm' => $this->algorithm,
        ];
        $envelope['signature'] = $this->sign($this->canonicalJson($envelope));

        return $envelope;
    }

    /** The five headers a signed push carries. */
    public function pushHeaders(string $method, string $path, string $requestId, int $timestamp, string $nonce, string $body): array
    {
        return [
            'X-VT-Request-ID' => $requestId,
            'X-VT-Timestamp' => (string) $timestamp,
            'X-VT-Nonce' => $nonce,
            'X-VT-Key-ID' => $this->keyId,
            'X-VT-Signature' => $this->sign($this->requestSigningString($method, $path, $requestId, $timestamp, $nonce, $body)),
        ];
    }

    /** The issuer serializes the push body once, and signs a digest of those exact bytes. */
    public function encodeBody(array $fields): string
    {
        return (string) json_encode($this->sortObjects($fields), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    public function sign(string $message): string
    {
        return base64_encode(sodium_crypto_sign_detached($message, $this->secretKey));
    }
}
