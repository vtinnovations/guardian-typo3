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

use Vtinnovations\GuardianTypo3\Domain\Configuration\ServiceRecord;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\CanonicalForm;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\DetachedSignature;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\SealedPackage;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\ReleaseKeyring;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\SigningKey;

/**
 * Mints real, correctly signed packages for the tests, using a throwaway key
 * pair generated in memory.
 *
 * It exists so the tests exercise the production verification path rather than a
 * stub of it: the same canonical form, the same signature primitive, the same
 * exact-byte digest. The private half never leaves this object and no vendor key
 * is involved — pinning a real key is a release step, not a test fixture.
 *
 * The order in which a package is built mirrors the protocol exactly. The
 * document is serialised ONCE; the digest and the Base64 payload are both taken
 * from those same bytes, so a test that mutates the bytes really does break the
 * digest, and a test that re-serialises really does break the signature.
 */
final class RecordPackageFactory
{
    private readonly string $secretKey;
    private readonly string $publicKeyBase64;

    public function __construct(
        public readonly string $keyId = 'vtone-2026a',
        public readonly string $algorithm = SigningKey::ALGORITHM_ED25519,
    ) {
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKeyBase64 = base64_encode(sodium_crypto_sign_publickey($pair));
    }

    /** A ring containing exactly this factory's key. */
    public function keyring(int $activeFrom = 0, ?int $retireAt = null, array $purposes = [SigningKey::PURPOSE_ANY]): ReleaseKeyring
    {
        $key = SigningKey::pin($this->keyId, $this->algorithm, $this->publicKeyBase64, $purposes, $activeFrom, $retireAt);
        self::assertPinned($key);

        return new ReleaseKeyring([$key]);
    }

    /** A ring containing a *different* key under the same identifier. */
    public function foreignKeyring(): ReleaseKeyring
    {
        $pair = sodium_crypto_sign_keypair();
        $key = SigningKey::pin(
            $this->keyId,
            $this->algorithm,
            base64_encode(sodium_crypto_sign_publickey($pair)),
        );
        self::assertPinned($key);

        return new ReleaseKeyring([$key]);
    }

    public function sealedPackage(?ReleaseKeyring $keyring = null): SealedPackage
    {
        return new SealedPackage(
            new CanonicalForm(),
            new DetachedSignature($keyring ?? $this->keyring()),
        );
    }

    /**
     * Builds a complete package.
     *
     * @param array<string, mixed> $overrides fields to change in the document
     * @return array{payload: string, envelope: array<string, mixed>, bytes: string, document: array<string, mixed>}
     */
    public function package(array $overrides = [], int $generatedAt = 1784880547): array
    {
        $document = $this->document($overrides);
        $bytes = $this->serialise($document);

        return [
            'payload' => base64_encode($bytes),
            'envelope' => $this->envelope($bytes, (int) $document['license_version'], $generatedAt),
            'bytes' => $bytes,
            'document' => $document,
        ];
    }

    /**
     * Builds the document with its own signature attached, in canonical order.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function document(array $overrides = []): array
    {
        $fields = [
            'schema_version' => ServiceRecord::SCHEMA_VERSION,
            'project' => ServiceRecord::PROJECT,
            'project_slug' => ServiceRecord::PROJECT_SLUG,
            'license_key' => 'GRD-TEST-0001-0002-0003',
            'license_domain' => 'example.com',
            'license_domains' => ['example.com'],
            'license_max_domains' => 3,
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
        foreach ($overrides as $name => $value) {
            $fields[$name] = $value;
        }
        // The authorised set follows the operation host unless a test is about
        // the set itself, so a test that only changes the host still produces a
        // document the issuer could have signed.
        if (!\array_key_exists('license_domains', $overrides)) {
            $fields['license_domains'] = [$fields['license_domain']];
        }
        if (!\array_key_exists('license_max_domains', $overrides)) {
            $fields['license_max_domains'] = 3;
        }
        // A record from before the issuer signed a host set: both fields absent.
        foreach (['license_domains', 'license_max_domains'] as $field) {
            if (\array_key_exists($field, $overrides) && $overrides[$field] === null) {
                unset($fields[$field]);
            }
        }
        // A lifetime record carries no expiry at all, not a falsy one.
        if (($fields['license_lifetime'] ?? false) === true && !\array_key_exists('license_expires_at', $overrides)) {
            $fields['license_expires_at'] = null;
        }

        $fields['signature'] = $this->sign((new CanonicalForm())->document($fields));

        return $fields;
    }

    /**
     * The exact bytes of a document. Called once per package so the digest, the
     * payload and the stored file are all the same string.
     *
     * @param array<string, mixed> $document
     */
    public function serialise(array $document): string
    {
        // Sorted-key encoding, matching what the issuer writes and therefore what its digest
        // and Base64 payload are taken from.
        ksort($document);
        $bytes = json_encode($document, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($bytes === false) {
            throw new \RuntimeException('The test document could not be serialised.');
        }

        return $bytes;
    }

    /**
     * @return array<string, mixed>
     */
    public function envelope(string $bytes, int $version, int $generatedAt = 1784880547): array
    {
        $envelope = [
            'project' => ServiceRecord::PROJECT,
            'project_slug' => ServiceRecord::PROJECT_SLUG,
            'license_version' => $version,
            'license_md5' => hash('md5', $bytes),
            'generated_at' => $generatedAt,
            'key_id' => $this->keyId,
            'signature_algorithm' => $this->algorithm,
        ];
        $envelope['signature'] = $this->sign((new CanonicalForm())->envelope($envelope));

        return $envelope;
    }

    /** Signs an inbound-request canonical message the way the vendor would. */
    public function signRequest(
        string $method,
        string $path,
        string $requestId,
        int $timestamp,
        string $nonce,
        string $rawBody,
    ): string {
        return $this->sign((new CanonicalForm())->request(
            $method,
            $path,
            $requestId,
            $timestamp,
            $nonce,
            hash('sha256', $rawBody),
        ));
    }

    public function sign(string $message): string
    {
        return base64_encode(sodium_crypto_sign_detached($message, $this->secretKey));
    }

    private static function assertPinned(?SigningKey $key): void
    {
        if ($key === null) {
            throw new \RuntimeException('The generated test key was rejected by the pinning rules.');
        }
    }
}
