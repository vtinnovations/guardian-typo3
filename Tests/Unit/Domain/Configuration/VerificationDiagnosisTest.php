<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Domain\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\Configuration\VerificationDiagnosis;

/**
 * The vocabulary that replaced one generic sentence.
 *
 * Two properties matter and are both asserted: an administrator must be able to
 * tell the failures apart, and none of the wording may carry packet material.
 */
final class VerificationDiagnosisTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function categories(): array
    {
        return [
            ['signing_key_store_empty', 'signing_key_store_empty'],
            ['unknown_signing_key', 'unknown_signing_key'],
            ['signing_key_not_usable', 'signing_key_retired'],
            ['signature_support_missing', 'signature_support_missing'],
            ['record_signature_invalid', 'signature_invalid'],
            ['record_unsigned', 'signature_invalid'],
            ['payload_digest_mismatch', 'integrity_signature_invalid'],
            ['envelope_incomplete', 'integrity_signature_invalid'],
            ['envelope_product_mismatch', 'integrity_signature_invalid'],
            ['envelope_version_mismatch', 'integrity_signature_invalid'],
            ['payload_invalid', 'response_schema_invalid'],
            ['response_malformed', 'response_schema_invalid'],
            ['unexpected_media_type', 'response_schema_invalid'],
            ['response_uncorrelated', 'request_correlation_failed'],
            ['response_clock_skew', 'server_clock_skew'],
            ['record_invalid_dates', 'license_dates_invalid'],
            ['record_invalid_product', 'license_product_mismatch'],
            ['record_invalid_domain', 'license_domain_invalid'],
            ['record_invalid', 'license_document_invalid'],
            ['host_binding_mismatch', 'domain_mismatch'],
            ['domain_mismatch', 'domain_mismatch'],
            ['key_binding_mismatch', 'license_key_mismatch'],
            ['host_unresolved', 'host_unresolved'],
            ['transport_failed', 'remote_verification_failed'],
            ['service_unavailable', 'remote_verification_failed'],
            ['storage_failed', 'license_storage_failed'],
            ['envelope_unreadable', 'license_storage_corrupt'],
            ['rejected_version', 'license_version_older'],
            ['denied', 'license_key_rejected'],
            ['withdrawn', 'license_withdrawn'],
        ];
    }

    #[Test]
    #[DataProvider('categories')]
    public function eachCategoryMapsToItsPublicCodeAndASentence(string $category, string $expectedCode): void
    {
        $diagnosis = VerificationDiagnosis::of($category);

        self::assertSame($expectedCode, $diagnosis->code);
        self::assertNotSame('', $diagnosis->message);
        self::assertMatchesRegularExpression('/[.!]$/', $diagnosis->message, 'the message should read as a sentence');
    }

    #[Test]
    public function anUnrecognisedCategoryFallsBackWithoutLeakingIt(): void
    {
        $diagnosis = VerificationDiagnosis::of('something_new_and_internal');

        self::assertSame(VerificationDiagnosis::FALLBACK, $diagnosis->code);
        self::assertStringNotContainsString('something_new_and_internal', $diagnosis->message);
    }

    #[Test]
    public function theMissingKeyAndTheWrongKeyAreDistinguishable(): void
    {
        // These need opposite responses: one is fixed by shipping a new Guardian
        // build, the other by checking key rotation. Collapsing them was the
        // whole problem.
        $empty = VerificationDiagnosis::of('signing_key_store_empty');
        $unknown = VerificationDiagnosis::of('unknown_signing_key');
        $badSignature = VerificationDiagnosis::of('record_signature_invalid');

        self::assertNotSame($empty->code, $unknown->code);
        self::assertNotSame($empty->code, $badSignature->code);
        self::assertNotSame($empty->message, $unknown->message);
        self::assertNotSame($empty->message, $badSignature->message);
    }

    #[Test]
    public function noMessageCarriesPacketMaterialOrInternalDetail(): void
    {
        foreach (self::categories() as [$category]) {
            $message = VerificationDiagnosis::of($category)->message;

            foreach ([
                'license_payload_b64',
                'license_md5',
                'signature=',
                'nonce',
                'sha256',
                'md5',
                'X-VT-',
                '/var/',
                'Classes/',
                'Exception',
                '#0 ',
            ] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $message, $category . ' / ' . $forbidden);
            }
            // Nothing that looks like encoded material.
            self::assertDoesNotMatchRegularExpression('/[A-Za-z0-9+\/]{24,}={0,2}/', $message, $category);
        }
    }

    #[Test]
    public function aRetainedLicenceIsSaidSoWithoutChangingTheCode(): void
    {
        $base = VerificationDiagnosis::of('transport_failed');
        $retained = $base->withRetainedLicence();

        self::assertSame($base->code, $retained->code);
        self::assertStringContainsString('still in effect', $retained->message);
    }
}
