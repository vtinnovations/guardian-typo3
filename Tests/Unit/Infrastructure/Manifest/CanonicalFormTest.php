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

/**
 * Fixed vectors for the issuer's two signing rules.
 *
 * These digests are the contract between this installation and the issuer. They are written down
 * rather than recomputed, because a change to the canonical form that goes unnoticed here would
 * surface as every genuine licence failing to verify — on customers' installations, where nobody
 * can see why.
 *
 * {@see \Vtinnovations\GuardianTypo3\Tests\Unit\Integration\IssuerInteroperabilityTest} proves the
 * same bytes against an independent transcription of the issuer's rules; this file pins them so a
 * drift is caught even if both sides were changed together.
 */
final class CanonicalFormTest extends TestCase
{
    private const VECTOR_DOCUMENT = 'a9127f6117b189e0f352036973ddc2a386631a1885581cb7986cf1c92a8bdd9f';
    private const VECTOR_ENVELOPE = '6fd0c705c28d93ccf8df0a4c47863509879ee026024a8d3f8e08cdfc54bb34d7';
    private const VECTOR_REQUEST = 'cd631faaface682c407d3ab55b12675ed095ccb30538d4cbd68cc719af09c962';

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        return [
            'schema_version' => 2,
            'project' => 'Guardian',
            'project_slug' => 'guardian',
            'license_key' => 'GRD-TEST-0001-0002-0003',
            'license_domain' => 'example.com',
            'license_package' => 'pro',
            'license_features' => ['updates', 'recovery'],
            'license_version' => 7,
            'license_issued_at' => 1784000000,
            'license_starts_at' => 1784000000,
            'license_expires_at' => 1815536000,
            'license_lifetime' => false,
            'license_verified_at' => 1784880547,
            'free_available' => true,
            'validation_status' => 'valid',
            'signature' => 'this-must-not-be-part-of-the-input',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(): array
    {
        return [
            'project' => 'Guardian',
            'project_slug' => 'guardian',
            'license_version' => 7,
            'license_md5' => '0123456789abcdef0123456789abcdef',
            'generated_at' => 1784880547,
            'key_id' => 'vtone-2026a',
            'signature_algorithm' => 'ed25519',
            'signature' => 'this-must-not-be-part-of-the-input',
        ];
    }

    #[Test]
    public function theDocumentVectorIsStable(): void
    {
        self::assertSame(self::VECTOR_DOCUMENT, hash('sha256', (new CanonicalForm())->document($this->document())));
    }

    #[Test]
    public function theEnvelopeVectorIsStable(): void
    {
        self::assertSame(self::VECTOR_ENVELOPE, hash('sha256', (new CanonicalForm())->envelope($this->envelope())));
    }

    #[Test]
    public function theRequestVectorIsStable(): void
    {
        $message = (new CanonicalForm())->request(
            'POST',
            '/rest/api/v1/guardian-license-updater',
            'req-0001',
            1784882547,
            'nonce-0001',
            '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
        );

        self::assertSame(self::VECTOR_REQUEST, hash('sha256', $message));
    }

    #[Test]
    public function theDocumentIsCanonicalJsonWithSortedKeys(): void
    {
        $canonical = (new CanonicalForm())->document($this->document());

        self::assertStringStartsWith('{"free_available":true,"license_domain":"example.com"', $canonical);
        self::assertJson($canonical);
    }

    #[Test]
    public function slashesAndUnicodeAreLeftUnescaped(): void
    {
        $canonical = (new CanonicalForm())->document([
            'license_domain' => 'example.com/path',
            'note' => 'Grüße',
        ]);

        self::assertStringContainsString('example.com/path', $canonical, 'slashes must not be escaped');
        self::assertStringContainsString('Grüße', $canonical, 'unicode must not be escaped');
    }

    #[Test]
    public function theSignatureFieldIsNeverPartOfTheInput(): void
    {
        $canonical = new CanonicalForm();
        $withOne = $canonical->document($this->document());
        $withAnother = $canonical->document(['signature' => 'completely-different'] + $this->document());

        self::assertSame($withOne, $withAnother);
        self::assertStringNotContainsString('this-must-not-be-part-of-the-input', $withOne);
    }

    #[Test]
    public function objectKeyOrderInTheInputDoesNotMatter(): void
    {
        $canonical = new CanonicalForm();

        self::assertSame(
            $canonical->document($this->document()),
            $canonical->document(array_reverse($this->document(), true)),
        );
    }

    #[Test]
    public function listOrderIsPreserved(): void
    {
        $canonical = new CanonicalForm();

        $asGiven = $canonical->document(['license_features' => ['updates', 'backup', 'recovery']]);
        $reordered = $canonical->document(['license_features' => ['backup', 'recovery', 'updates']]);

        // The issuer signs the order an administrator configured; sorting it here would reject
        // every genuine licence.
        self::assertStringContainsString('["updates","backup","recovery"]', $asGiven);
        self::assertNotSame($asGiven, $reordered);
    }

    #[Test]
    public function nestedObjectsAreSortedTooButNestedListsAreNot(): void
    {
        $canonical = (new CanonicalForm())->document([
            'outer' => ['b' => 1, 'a' => ['z', 'a']],
        ]);

        self::assertSame('{"outer":{"a":["z","a"],"b":1}}', $canonical);
    }

    #[Test]
    public function jsonKeepsScalarTypesApartOnItsOwn(): void
    {
        $canonical = new CanonicalForm();

        // No type tagging is needed: JSON already distinguishes these, so one value cannot be
        // presented in place of another.
        self::assertNotSame(
            $canonical->document(['license_lifetime' => false]),
            $canonical->document(['license_lifetime' => 'false']),
        );
        self::assertNotSame(
            $canonical->document(['license_version' => 7]),
            $canonical->document(['license_version' => '7']),
        );
        self::assertNotSame(
            $canonical->document(['license_expires_at' => null]),
            $canonical->document(['license_expires_at' => 0]),
        );
    }

    #[Test]
    public function theRequestInputIsSixNewlineJoinedValuesAndNamesNoKey(): void
    {
        $message = (new CanonicalForm())->request(
            'post',
            '/rest/api/v1/guardian-license-updater',
            'req-1',
            1784882547,
            'nonce-1',
            'ABCDEF0123456789',
        );

        $parts = explode("\n", $message);
        self::assertCount(6, $parts);
        self::assertSame('POST', $parts[0], 'the method is uppercased');
        self::assertSame('/rest/api/v1/guardian-license-updater', $parts[1]);
        self::assertSame('req-1', $parts[2]);
        self::assertSame('1784882547', $parts[3], 'the timestamp is its decimal string');
        self::assertSame('nonce-1', $parts[4]);
        self::assertSame('abcdef0123456789', $parts[5], 'the body digest is lower-case hex');
        self::assertStringNotContainsString('vtone-', $message, 'the key id selects a key; it is not signed');
    }

    #[Test]
    public function theRequestInputBindsTheMethodAndThePath(): void
    {
        $canonical = new CanonicalForm();
        $digest = hash('sha256', '{}');

        $post = $canonical->request('POST', '/a', 'r', 1, 'n', $digest);
        $put = $canonical->request('PUT', '/a', 'r', 1, 'n', $digest);
        $otherPath = $canonical->request('POST', '/b', 'r', 1, 'n', $digest);

        self::assertNotSame($post, $put);
        self::assertNotSame($post, $otherPath);
    }

    #[Test]
    public function anUnencodableStructureYieldsNoInputRatherThanAPartialOne(): void
    {
        // Invalid UTF-8 cannot be represented, and a caller must treat the empty result as
        // "cannot verify" rather than as a passing check.
        self::assertSame('', (new CanonicalForm())->document(['project' => "\xB1\x31"]));
    }
}
