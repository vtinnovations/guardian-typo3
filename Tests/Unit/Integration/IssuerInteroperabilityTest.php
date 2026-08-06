<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\CanonicalForm;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\DetachedSignature;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\SealedPackage;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\ReleaseKeyring;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\SigningKey;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\IssuerSimulator;

/**
 * Whether Guardian agrees with the issuer, rather than merely with itself.
 *
 * Every packet here is produced by {@see IssuerSimulator}, which transcribes the issuer's own
 * published rules and never calls Guardian's code. If Guardian's canonicalisation drifts by a
 * single byte, nothing below verifies.
 *
 * This is the check that was impossible before the two implementations shared a format.
 */
final class IssuerInteroperabilityTest extends TestCase
{
    private const NOW = 1784880600;

    private IssuerSimulator $issuer;

    protected function setUp(): void
    {
        $this->issuer = new IssuerSimulator();
    }

    private function keyring(): ReleaseKeyring
    {
        $key = SigningKey::pin($this->issuer->keyId, $this->issuer->algorithm, $this->issuer->publicKeyBase64);
        self::assertNotNull($key, 'the issuer key was rejected by the pinning rules');

        return new ReleaseKeyring([$key]);
    }

    private function sealed(?ReleaseKeyring $ring = null): SealedPackage
    {
        return new SealedPackage(new CanonicalForm(), new DetachedSignature($ring ?? $this->keyring()));
    }

    // ── The canonical forms themselves ──────────────────────────────────────

    #[Test]
    public function guardianBuildsTheSameDocumentBytesAsTheIssuer(): void
    {
        $document = $this->issuer->document();

        self::assertSame(
            $this->issuer->canonicalJson($document),
            (new CanonicalForm())->document($document),
        );
    }

    #[Test]
    public function guardianBuildsTheSameEnvelopeBytesAsTheIssuer(): void
    {
        $package = $this->issuer->package();

        self::assertSame(
            $this->issuer->canonicalJson($package['envelope']),
            (new CanonicalForm())->envelope($package['envelope']),
        );
    }

    #[Test]
    public function guardianBuildsTheSameRequestSigningStringAsTheIssuer(): void
    {
        $body = '{"action":"license_update"}';
        $path = '/rest/api/v1/guardian-license-updater';

        self::assertSame(
            $this->issuer->requestSigningString('POST', $path, 'req-1', 1784882547, 'nonce-1', $body),
            (new CanonicalForm())->request('POST', $path, 'req-1', 1784882547, 'nonce-1', hash('sha256', $body)),
        );
    }

    #[Test]
    public function theRequestSigningStringDoesNotIncludeTheKeyId(): void
    {
        // The issuer's rule names six values, and the key id is not one of them. Including it was
        // the defect that made every push fail authentication.
        $message = (new CanonicalForm())->request(
            'POST',
            '/rest/api/v1/guardian-license-updater',
            'req-1',
            1784882547,
            'nonce-1',
            hash('sha256', '{}'),
        );

        self::assertStringNotContainsString('vtone-2026a', $message);
        self::assertCount(6, explode("\n", $message));
    }

    #[Test]
    public function listOrderIsPreservedRatherThanSorted(): void
    {
        // license_features is the only list in the contract and its order is meaningful. A client
        // that sorted it would produce different bytes and reject every genuine licence.
        $document = $this->issuer->document(['license_features' => ['updates', 'backup', 'recovery']]);
        $canonical = (new CanonicalForm())->document($document);

        self::assertStringContainsString('["updates","backup","recovery"]', $canonical);
        self::assertSame($this->issuer->canonicalJson($document), $canonical);
    }

    #[Test]
    public function objectKeysAreSortedRecursively(): void
    {
        $document = $this->issuer->document();
        $shuffled = array_reverse($document, true);

        self::assertSame(
            (new CanonicalForm())->document($document),
            (new CanonicalForm())->document($shuffled),
        );
    }

    #[Test]
    public function theSignatureFieldNeverSignsItself(): void
    {
        $document = $this->issuer->document();
        $tampered = $document;
        $tampered['signature'] = 'something-completely-different';

        self::assertSame(
            (new CanonicalForm())->document($document),
            (new CanonicalForm())->document($tampered),
        );
    }

    // ── Whole packets ───────────────────────────────────────────────────────

    #[Test]
    public function anIssuerProducedPackageIsAccepted(): void
    {
        $package = $this->issuer->package();

        $result = $this->sealed()->open($package['payload'], $package['envelope'], self::NOW);

        self::assertTrue($result->trusted, $result->category);
        self::assertNotNull($result->record);
        self::assertSame('example.com', $result->record->host);
        self::assertSame(7, $result->record->version);
        self::assertSame($package['bytes'], $result->documentBytes);
    }

    #[Test]
    public function anIssuerProducedLifetimePackageIsAccepted(): void
    {
        $package = $this->issuer->package(['license_lifetime' => true]);

        $result = $this->sealed()->open($package['payload'], $package['envelope'], self::NOW);

        self::assertTrue($result->trusted, $result->category);
        self::assertTrue($result->record->lifetime);
        self::assertNull($result->record->expiresAt);
    }

    #[Test]
    public function anIssuerProducedFreeFallbackPackageIsAccepted(): void
    {
        // The issuer renders a lapsed paid licence as its Free entitlement: free package, perpetual.
        $package = $this->issuer->package([
            'license_package' => 'free',
            'license_lifetime' => true,
            'free_available' => true,
        ]);

        $result = $this->sealed()->open($package['payload'], $package['envelope'], self::NOW);

        self::assertTrue($result->trusted, $result->category);
        self::assertSame('free', $result->record->package);
    }

    #[Test]
    public function anIssuerPackageForAnotherProductsTierIsRefused(): void
    {
        // "free" and "pro" are this product's vocabulary; a package value from a
        // different catalogue entry is refused for what it names, not for its
        // signature.
        $package = $this->issuer->package(['license_package' => 'enterprise']);

        $result = $this->sealed()->open($package['payload'], $package['envelope'], self::NOW);

        self::assertFalse($result->trusted);
        self::assertSame('record_invalid_product', $result->category);
        self::assertNull($result->record);
    }

    #[Test]
    public function anIssuerPackageForAWwwHostKeepsItsPrefix(): void
    {
        // The exact-host contract: the issuer binds the host that activated, "www." included.
        $package = $this->issuer->package(['license_domain' => 'www.example.com']);

        $result = $this->sealed()->open($package['payload'], $package['envelope'], self::NOW);

        self::assertTrue($result->trusted, $result->category);
        self::assertSame('www.example.com', $result->record->host);
    }

    #[Test]
    public function aRecordSignedBeforeARotationStillVerifiesAfterIt(): void
    {
        // The record names no key, so it is tried against every key held. A build carrying both
        // the new and the previous key keeps working across the cutover.
        $previous = $this->issuer;
        $current = new IssuerSimulator('vtone-2027b');

        $ring = new ReleaseKeyring([
            SigningKey::pin($current->keyId, $current->algorithm, $current->publicKeyBase64),
            SigningKey::pin($previous->keyId, $previous->algorithm, $previous->publicKeyBase64),
        ]);

        // Envelope from the current key, record signed by the previous one.
        $document = $previous->document();
        $bytes = $previous->encodeBody($document);
        $envelope = $current->envelope($bytes, 7);

        $result = $this->sealed($ring)->open(base64_encode($bytes), $envelope, self::NOW);

        self::assertTrue($result->trusted, $result->category);
    }

    #[Test]
    public function aRecordSignedByNoHeldKeyIsRefused(): void
    {
        $stranger = new IssuerSimulator('vtone-2026a');
        $document = $stranger->document();
        $bytes = $stranger->encodeBody($document);
        // Envelope from the key we do hold, record from one we do not.
        $envelope = $this->issuer->envelope($bytes, 7);

        $result = $this->sealed()->open(base64_encode($bytes), $envelope, self::NOW);

        self::assertFalse($result->trusted);
        self::assertSame('record_signature_invalid', $result->category);
    }

    #[Test]
    public function aTamperedIssuerPackageIsStillRefused(): void
    {
        // Adopting the issuer's format must not have loosened anything.
        $package = $this->issuer->package();
        $forged = str_replace('"license_expires_at":1815536000', '"license_expires_at":2130000000', $package['bytes']);
        self::assertNotSame($package['bytes'], $forged);

        $result = $this->sealed()->open(base64_encode($forged), $package['envelope'], self::NOW);

        self::assertFalse($result->trusted);
        self::assertSame('payload_digest_mismatch', $result->category);
    }

    #[Test]
    public function recomputingTheDigestOnAForgedPayloadIsStillRefused(): void
    {
        $package = $this->issuer->package();
        $forged = str_replace('"license_expires_at":1815536000', '"license_expires_at":2130000000', $package['bytes']);
        $envelope = $package['envelope'];
        $envelope['license_md5'] = md5($forged);

        $result = $this->sealed()->open(base64_encode($forged), $envelope, self::NOW);

        self::assertFalse($result->trusted);
        self::assertSame('envelope_signature_invalid', $result->category);
    }

    #[Test]
    public function anEmptyKeyRingStillRefusesAnIssuerPackage(): void
    {
        $package = $this->issuer->package();

        $result = $this->sealed(new ReleaseKeyring([]))->open($package['payload'], $package['envelope'], self::NOW);

        self::assertFalse($result->trusted);
        self::assertSame(ReleaseKeyring::NO_KEYS, $result->category);
    }
}
