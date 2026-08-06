<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Manifest;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\CanonicalForm;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\DetachedSignature;
use Vtinnovations\GuardianTypo3\Infrastructure\Manifest\SealedPackage;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\ReleaseKeyring;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\SigningKey;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\RecordPackageFactory;

/**
 * End-to-end checks on opening a package, driven through the real verification
 * code with a real signature primitive.
 */
final class SealedPackageTest extends TestCase
{
    private const NOW = 1784880600;

    private RecordPackageFactory $vendor;

    protected function setUp(): void
    {
        $this->vendor = new RecordPackageFactory();
    }

    #[Test]
    public function acorrectlySignedPackageOpens(): void
    {
        $package = $this->vendor->package();
        $result = $this->vendor->sealedPackage()->open($package['payload'], $package['envelope'], self::NOW);

        self::assertTrue($result->trusted, $result->category);
        self::assertNotNull($result->record);
        self::assertSame('example.com', $result->record->host);
        self::assertSame(7, $result->record->version);
        // The bytes handed back are exactly what was transmitted.
        self::assertSame($package['bytes'], $result->documentBytes);
    }

    #[Test]
    public function aSingleChangedByteBreaksTheDigest(): void
    {
        $package = $this->vendor->package();
        $mutated = str_replace('"license_package":"pro"', '"license_package":"Pro"', $package['bytes']);
        self::assertNotSame($package['bytes'], $mutated);

        $result = $this->vendor->sealedPackage()->open(base64_encode($mutated), $package['envelope'], self::NOW);

        self::assertFalse($result->trusted);
        self::assertSame('payload_digest_mismatch', $result->category);
    }

    #[Test]
    public function insignificantWhitespaceStillBreaksTheDigest(): void
    {
        $package = $this->vendor->package();
        // Semantically identical JSON, different bytes.
        $reformatted = json_encode($package['document'], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        self::assertIsString($reformatted);
        self::assertNotSame($package['bytes'], $reformatted);

        $result = $this->vendor->sealedPackage()->open(base64_encode($reformatted), $package['envelope'], self::NOW);

        self::assertFalse($result->trusted);
        self::assertSame('payload_digest_mismatch', $result->category);
    }

    #[Test]
    public function recomputingTheDigestOverAlteredBytesDoesNotHelpBecauseTheEnvelopeIsSigned(): void
    {
        // Start from a genuine record and try to extend its term.
        $package = $this->vendor->package();
        $forged = str_replace('"license_expires_at":1815536000', '"license_expires_at":2130000000', $package['bytes']);
        self::assertNotSame($package['bytes'], $forged);
        $envelope = $package['envelope'];
        // The attacker "fixes" the digest to match their edit.
        $envelope['license_md5'] = hash('md5', $forged);

        $result = $this->vendor->sealedPackage()->open(base64_encode($forged), $envelope, self::NOW);

        self::assertFalse($result->trusted);
        // The key was available and usable; the envelope signature simply did
        // not match the edited digest.
        self::assertSame('envelope_signature_invalid', $result->category);
    }

    #[Test]
    public function aPayloadThatIsNotStrictBase64IsRefused(): void
    {
        $package = $this->vendor->package();
        $sealed = $this->vendor->sealedPackage();

        $mutations = [
            'with leading whitespace' => "\n" . $package['payload'],
            'with inner whitespace' => substr($package['payload'], 0, 8) . "\n" . substr($package['payload'], 8),
            'with a url-safe alphabet' => '-' . substr($package['payload'], 1),
            'with an alien character' => '*' . substr($package['payload'], 1),
            'truncated' => substr($package['payload'], 0, -4),
            // Length no longer a multiple of four: not the one canonical encoding
            // of any byte string, whatever it may decode to.
            'a character short' => substr($package['payload'], 0, -1),
            'empty' => '',
        ];
        // Only a payload that actually carries padding can have it removed; the
        // document's length decides that, so the case is added when it applies.
        if (str_ends_with($package['payload'], '=')) {
            $mutations['unpadded'] = rtrim($package['payload'], '=');
        }

        foreach ($mutations as $why => $payload) {
            $result = $sealed->open($payload, $package['envelope'], self::NOW);
            self::assertFalse($result->trusted, $why);
        }
    }

    #[Test]
    public function aDocumentWithoutItsOwnSignatureIsRefused(): void
    {
        $document = $this->vendor->document();
        unset($document['signature']);
        $bytes = $this->vendor->serialise($document);
        $envelope = $this->vendor->envelope($bytes, 7);

        $result = $this->vendor->sealedPackage()->open(base64_encode($bytes), $envelope, self::NOW);

        self::assertFalse($result->trusted);
        self::assertSame('record_unsigned', $result->category);
    }

    #[Test]
    public function aDocumentSignedByTheWrongKeyIsRefused(): void
    {
        $document = $this->vendor->document();
        $document['signature'] = (new RecordPackageFactory())->sign((new CanonicalForm())->document($document));
        $bytes = $this->vendor->serialise($document);
        $envelope = $this->vendor->envelope($bytes, 7);

        $result = $this->vendor->sealedPackage()->open(base64_encode($bytes), $envelope, self::NOW);

        self::assertFalse($result->trusted);
        self::assertSame('record_signature_invalid', $result->category);
    }

    #[Test]
    public function anEmptyKeyRingRefusesEverythingWithoutFallingBackToTheDigest(): void
    {
        $package = $this->vendor->package();
        $sealed = new SealedPackage(new CanonicalForm(), new DetachedSignature(new ReleaseKeyring([])));

        $result = $sealed->open($package['payload'], $package['envelope'], self::NOW);

        self::assertFalse($result->trusted);
        self::assertSame(ReleaseKeyring::NO_KEYS, $result->category);
    }

    #[Test]
    public function anUnknownKeyIdentifierIsRefused(): void
    {
        $package = $this->vendor->package();
        $envelope = $package['envelope'];
        $envelope['key_id'] = 'vtone-9999z';

        $result = $this->vendor->sealedPackage()->open($package['payload'], $envelope, self::NOW);

        self::assertFalse($result->trusted);
        self::assertSame(ReleaseKeyring::UNKNOWN_KEY, $result->category);
    }

    #[Test]
    public function aMismatchedAlgorithmIsRefused(): void
    {
        $package = $this->vendor->package();
        $envelope = $package['envelope'];
        $envelope['signature_algorithm'] = 'rsa';

        $result = $this->vendor->sealedPackage()->open($package['payload'], $envelope, self::NOW);

        self::assertFalse($result->trusted);
    }

    #[Test]
    public function aRetiredKeyIsRefusedEvenThoughTheSignatureIsGenuine(): void
    {
        $package = $this->vendor->package();
        $sealed = $this->vendor->sealedPackage($this->vendor->keyring(0, self::NOW - 1));

        $result = $sealed->open($package['payload'], $package['envelope'], self::NOW);

        self::assertFalse($result->trusted);
        self::assertSame(ReleaseKeyring::KEY_NOT_USABLE, $result->category);
    }

    #[Test]
    public function aKeyPinnedOnlyForRequestsCannotVouchForARecord(): void
    {
        $package = $this->vendor->package();
        $sealed = $this->vendor->sealedPackage($this->vendor->keyring(0, null, [SigningKey::PURPOSE_REQUEST]));

        self::assertFalse($sealed->open($package['payload'], $package['envelope'], self::NOW)->trusted);
    }

    #[Test]
    public function anEnvelopeForAnotherProductIsRefused(): void
    {
        $package = $this->vendor->package();
        $envelope = $package['envelope'];
        $envelope['project'] = 'SomethingElse';

        $result = $this->vendor->sealedPackage()->open($package['payload'], $envelope, self::NOW);

        self::assertFalse($result->trusted);
        self::assertSame('envelope_product_mismatch', $result->category);
    }

    #[Test]
    public function anEnvelopeThatDisagreesWithTheDocumentAboutTheVersionIsRefused(): void
    {
        $document = $this->vendor->document(['license_version' => 7]);
        $bytes = $this->vendor->serialise($document);
        // A genuinely signed envelope that nevertheless names a different version.
        $envelope = $this->vendor->envelope($bytes, 9);

        $result = $this->vendor->sealedPackage()->open(base64_encode($bytes), $envelope, self::NOW);

        self::assertFalse($result->trusted);
        self::assertSame('envelope_version_mismatch', $result->category);
    }

    #[Test]
    public function anIncompleteEnvelopeIsRefusedBeforeAnythingIsParsed(): void
    {
        $package = $this->vendor->package();
        $sealed = $this->vendor->sealedPackage();

        foreach (['key_id', 'signature_algorithm', 'signature', 'license_version', 'license_md5'] as $field) {
            $envelope = $package['envelope'];
            unset($envelope[$field]);
            $result = $sealed->open($package['payload'], $envelope, self::NOW);
            self::assertFalse($result->trusted, $field);
        }
    }

    #[Test]
    public function aDigestThatIsNotADigestIsRefused(): void
    {
        $package = $this->vendor->package();
        $envelope = $package['envelope'];
        $envelope['license_md5'] = 'not-a-digest';

        self::assertSame(
            'envelope_incomplete',
            $this->vendor->sealedPackage()->open($package['payload'], $envelope, self::NOW)->category
        );
    }

    #[Test]
    public function anOversizedPayloadIsRefusedBeforeItIsParsed(): void
    {
        $bytes = str_repeat('a', SealedPackage::MAX_DOCUMENT_BYTES + 1);
        $envelope = $this->vendor->envelope($bytes, 7);

        $result = $this->vendor->sealedPackage()->open(base64_encode($bytes), $envelope, self::NOW);

        self::assertFalse($result->trusted);
        self::assertSame('payload_invalid', $result->category);
    }
}
