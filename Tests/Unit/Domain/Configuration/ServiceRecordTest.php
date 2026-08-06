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
use Vtinnovations\GuardianTypo3\Domain\Configuration\ServiceRecord;
use Vtinnovations\GuardianTypo3\Domain\Environment\CapabilityTier;

/**
 * The protocol invariants a record document must satisfy before it becomes a
 * record at all.
 */
final class ServiceRecordTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function document(array $overrides = []): array
    {
        return $overrides + [
            'schema_version' => 2,
            'project' => 'Guardian',
            'project_slug' => 'guardian',
            'license_key' => 'GRD-0001',
            'license_domain' => 'example.com',
            'license_domains' => ['example.com'],
            'license_max_domains' => 3,
            'license_package' => 'pro',
            'license_features' => ['updates'],
            'license_version' => 7,
            'license_issued_at' => 1784000000,
            'license_starts_at' => 1784000000,
            'license_expires_at' => 1815536000,
            'license_lifetime' => false,
            'license_verified_at' => 1784880547,
            'free_available' => true,
            'validation_status' => 'valid',
            'signature' => 'c2ln',
        ];
    }

    private function at(int $timestamp): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $timestamp);
    }

    #[Test]
    public function aCompleteDocumentBecomesARecord(): void
    {
        $record = ServiceRecord::fromDocument($this->document());

        self::assertNotNull($record);
        self::assertSame('GRD-0001', $record->key);
        self::assertSame('example.com', $record->host);
        self::assertSame(7, $record->version);
        self::assertSame(1784000000, $record->issuedAt);
        self::assertSame(1815536000, $record->expiresAt);
        self::assertFalse($record->lifetime);
        self::assertSame(['updates'], $record->features);
    }

    #[Test]
    public function aLifetimeRecordCarriesNoExpiryAndNeverExpires(): void
    {
        $record = ServiceRecord::fromDocument($this->document([
            'license_lifetime' => true,
            'license_expires_at' => null,
        ]));

        self::assertNotNull($record);
        self::assertNull($record->expiresAt);
        self::assertFalse($record->isExpired($this->at(4102444800)));
        self::assertTrue($record->isEffective($this->at(4102444800)));
    }

    #[Test]
    public function aNonLifetimeRecordWithoutAnExpiryIsRejected(): void
    {
        $document = $this->document(['license_lifetime' => false]);
        unset($document['license_expires_at']);

        self::assertNull(ServiceRecord::fromDocument($document));
        self::assertNull(ServiceRecord::fromDocument($this->document([
            'license_lifetime' => false,
            'license_expires_at' => null,
        ])));
    }

    #[Test]
    public function aLifetimeRecordThatAlsoCarriesAnExpiryIsRejected(): void
    {
        self::assertNull(ServiceRecord::fromDocument($this->document([
            'license_lifetime' => true,
            'license_expires_at' => 1815536000,
        ])));
    }

    /**
     * @return list<array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidDocuments(): array
    {
        return [
            [['schema_version' => 1], 'an older schema is not understood'],
            [['schema_version' => '2'], 'the schema version must be an integer'],
            [['project' => 'Brickie'], 'another product'],
            [['project_slug' => 'brickie'], 'another slug'],
            [['license_key' => ''], 'an empty key'],
            [['license_key' => 123], 'a non-string key'],
            [['license_domain' => 'EXAMPLE.COM'], 'a host that is not already canonical'],
            [['license_domain' => 'example.com.'], 'a host that is not already canonical'],
            [['license_domain' => '*.example.com'], 'a wildcard host'],
            [['license_domain' => ''], 'no host'],
            [['license_package' => 'PRO'], 'a package that is not lower case'],
            [['license_package' => ''], 'no package'],
            [['license_features' => 'updates'], 'features must be a list'],
            [['license_features' => ['a' => 'b']], 'features must be a list'],
            [['license_features' => [1]], 'features must be strings'],
            [['license_version' => 0], 'a version must be positive'],
            [['license_version' => '7'], 'a version must be an integer'],
            [['license_issued_at' => 0], 'an issue date is mandatory'],
            [['license_starts_at' => null], 'a start date is mandatory'],
            [['license_expires_at' => 1783000000], 'an expiry before the start'],
            [['license_lifetime' => 'false'], 'the lifetime flag must be a boolean'],
            [['license_verified_at' => -1], 'a negative confirmation time'],
            [['free_available' => 1], 'the fallback flag must be a boolean'],
            [['signature' => ''], 'an unsigned document'],
            [['validation_status' => 'unknown'], 'an unrecognised status'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[Test]
    #[DataProvider('invalidDocuments')]
    public function anInvalidDocumentIsRejectedOutright(array $overrides, string $why): void
    {
        self::assertNull(ServiceRecord::fromDocument($this->document($overrides)), $why);
    }

    #[Test]
    public function anOverlongKeyIsRejected(): void
    {
        self::assertNull(ServiceRecord::fromDocument($this->document([
            'license_key' => str_repeat('k', ServiceRecord::MAX_KEY_LENGTH + 1),
        ])));
    }

    #[Test]
    public function aRecordBeforeItsStartDateIsNotYetEffective(): void
    {
        $record = ServiceRecord::fromDocument($this->document([
            'license_starts_at' => 1790000000,
            'license_expires_at' => 1815536000,
        ]));

        self::assertNotNull($record);
        self::assertFalse($record->hasStarted($this->at(1789999999)));
        self::assertFalse($record->isEffective($this->at(1789999999)));
        self::assertTrue($record->isEffective($this->at(1790000000)));
    }

    #[Test]
    public function theStartDateGatesValidityIndependentlyOfTheIssueDate(): void
    {
        $record = ServiceRecord::fromDocument($this->document([
            'license_issued_at' => 1700000000,
            'license_starts_at' => 1790000000,
        ]));

        self::assertNotNull($record);
        self::assertSame(1700000000, $record->issuedAt);
        self::assertFalse($record->isEffective($this->at(1780000000)));
    }

    #[Test]
    public function anExpiredProRecordFallsBackToFreeWhenTheVendorAllowsIt(): void
    {
        $expired = $this->at(1815536001);

        $withFallback = ServiceRecord::fromDocument($this->document(['free_available' => true]));
        self::assertNotNull($withFallback);
        self::assertTrue($withFallback->isExpired($expired));
        self::assertFalse($withFallback->isEffective($expired));
        self::assertTrue($withFallback->hasFreeFallback($expired));
        self::assertSame(CapabilityTier::Free, $withFallback->tier($expired));

        $without = ServiceRecord::fromDocument($this->document(['free_available' => false]));
        self::assertNotNull($without);
        self::assertFalse($without->hasFreeFallback($expired));
        self::assertSame(CapabilityTier::None, $without->tier($expired));
    }

    #[Test]
    public function anExpiredFreeRecordDoesNotGainAFallback(): void
    {
        // There is no tier below Free, so the flag has nothing to authorise.
        $record = ServiceRecord::fromDocument($this->document([
            'license_package' => 'free',
            'free_available' => true,
        ]));

        self::assertNotNull($record);
        self::assertFalse($record->hasFreeFallback($this->at(1815536001)));
        self::assertSame(CapabilityTier::None, $record->tier($this->at(1815536001)));
    }

    #[Test]
    public function aFreeRecordIsAcceptedAndGrantsFree(): void
    {
        $record = ServiceRecord::fromDocument($this->document(['license_package' => 'free']));

        self::assertNotNull($record);
        self::assertSame('free', $record->package);
        self::assertSame(CapabilityTier::Free, $record->tier($this->at(1784880547)));
    }

    #[Test]
    public function aRecordTheVendorMarkedInvalidGrantsNothing(): void
    {
        foreach (['invalid', 'suspended', 'revoked'] as $status) {
            $record = ServiceRecord::fromDocument($this->document(['validation_status' => $status]));
            self::assertNotNull($record, $status);
            self::assertFalse($record->isEffective($this->at(1784880547)), $status);
            self::assertFalse($record->hasFreeFallback($this->at(1815536001)), $status);
            self::assertSame(CapabilityTier::None, $record->tier($this->at(1784880547)), $status);
        }
    }

    /**
     * @return list<array{0: string}>
     */
    public static function packagesThisProductDoesNotSell(): array
    {
        return [['trial'], ['enterprise'], ['starter'], ['basic']];
    }

    /**
     * The product is sold as "free" or "pro". A document naming anything else is
     * not a lesser entitlement to be interpreted generously — it is refused at
     * the edge, so no later stage has to decide what to do with it.
     */
    #[Test]
    #[DataProvider('packagesThisProductDoesNotSell')]
    public function aPackageThisProductDoesNotSellIsRefused(string $package): void
    {
        $reason = null;
        self::assertNull(ServiceRecord::fromDocument($this->document(['license_package' => $package]), $reason));
        self::assertSame('product', $reason);
    }

    #[Test]
    public function theKeyIsOnlyEverExposedMasked(): void
    {
        $record = ServiceRecord::fromDocument($this->document(['license_key' => 'GRD-ABCD-EFGH-IJKL']));

        self::assertNotNull($record);
        $masked = $record->maskedKey();
        self::assertStringStartsWith('GRD-', $masked);
        self::assertStringEndsWith('IJKL', $masked);
        self::assertStringNotContainsString('ABCD-EFGH', $masked);
        self::assertSame(strlen($record->key), mb_strlen($masked));
    }

    #[Test]
    public function aStaleConfirmationIsFlaggedWithoutAffectingValidity(): void
    {
        $record = ServiceRecord::fromDocument($this->document(['license_verified_at' => 1784000000]));

        self::assertNotNull($record);
        self::assertTrue($record->isConfirmationStale($this->at(1784000000 + 90000)));
        self::assertFalse($record->isConfirmationStale($this->at(1784000000 + 10)));
        // Staleness is advisory: the record still works offline until it expires.
        self::assertTrue($record->isEffective($this->at(1784000000 + 90000)));
    }

    // ── The signed host set ─────────────────────────────────────────────────

    #[Test]
    public function theSignedHostSetIsReadAsTheOnlySourceOfAuthorisation(): void
    {
        $record = ServiceRecord::fromDocument($this->document([
            'license_domain' => 'shop.example.com',
            'license_domains' => ['example.com', 'shop.example.com', 'www.example.com'],
            'license_max_domains' => 5,
        ]));

        self::assertNotNull($record);
        self::assertFalse($record->predatesDomainSet());
        self::assertSame(['example.com', 'shop.example.com', 'www.example.com'], $record->authorizedDomains());
        self::assertSame(5, $record->maxDomains);

        foreach (['example.com', 'shop.example.com', 'www.example.com', 'EXAMPLE.COM', 'example.com.'] as $member) {
            self::assertTrue($record->authorizes($member), $member);
        }
        // Neighbours of every kind. None of them is in the set, so none of them
        // is authorised, whatever their relationship to one that is.
        foreach ([
            'blog.example.com',
            'admin.shop.example.com',
            'malicious-example.com',
            'example.com.attacker.test',
            'com',
            '',
        ] as $stranger) {
            self::assertFalse($record->authorizes($stranger), $stranger);
        }
    }

    /**
     * @return list<array{0: array<string, mixed>, 1: string}>
     */
    public static function unusableHostSets(): array
    {
        return [
            [['license_domains' => []], 'an empty set authorises nothing and is not a shape the vendor emits'],
            [['license_domains' => ['b.example.com', 'a.example.com'], 'license_domain' => 'a.example.com'], 'not sorted'],
            [['license_domains' => ['a.example.com', 'a.example.com'], 'license_domain' => 'a.example.com'], 'duplicated'],
            [['license_domains' => ['*.example.com', 'example.com']], 'a wildcard is not a host'],
            [['license_domains' => ['EXAMPLE.COM']], 'not already canonical'],
            [['license_domains' => ['example.com.']], 'not already canonical'],
            [['license_domains' => ['example.com:8443']], 'not already canonical'],
            [['license_domains' => 'example.com'], 'a string is not a set'],
            [['license_domains' => ['example.com' => true]], 'a map is not a list'],
            [['license_domains' => [123]], 'a number is not a host'],
            [['license_domains' => ['other.example.com']], 'the operation host is not a member'],
            [['license_max_domains' => 0], 'the allowance must be positive'],
            [['license_max_domains' => -1], 'the allowance must be positive'],
            [['license_max_domains' => '3'], 'the allowance must be an integer'],
            [['license_max_domains' => 3.5], 'the allowance must be an integer'],
        ];
    }

    #[Test]
    #[DataProvider('unusableHostSets')]
    public function anUnusableHostSetIsRejectedRatherThanRepaired(array $overrides, string $why): void
    {
        self::assertNull(ServiceRecord::fromDocument($this->document($overrides), $reason), $why);
        self::assertSame('domain', $reason, $why);
    }

    #[Test]
    public function theSetAndTheAllowanceMustArriveTogether(): void
    {
        $withoutAllowance = $this->document();
        unset($withoutAllowance['license_max_domains']);
        self::assertNull(ServiceRecord::fromDocument($withoutAllowance));

        $withoutSet = $this->document();
        unset($withoutSet['license_domains']);
        self::assertNull(ServiceRecord::fromDocument($withoutSet));
    }

    #[Test]
    public function aSetLargerThanTheAllowanceIsStillAccepted(): void
    {
        // The vendor lowers an allowance without unbinding what is already bound.
        // Counting here would invent a refusal it never made.
        $record = ServiceRecord::fromDocument($this->document([
            'license_domain' => 'a.example.com',
            'license_domains' => ['a.example.com', 'b.example.com', 'c.example.com'],
            'license_max_domains' => 1,
        ]));

        self::assertNotNull($record);
        self::assertTrue($record->authorizes('c.example.com'));
    }

    #[Test]
    public function anAllowanceOfNineThousandNineHundredAndNinetyNineIsNotAWildcard(): void
    {
        $record = ServiceRecord::fromDocument($this->document([
            'license_domains' => ['example.com'],
            'license_max_domains' => 9999,
        ]));

        self::assertNotNull($record);
        self::assertTrue($record->authorizes('example.com'));
        self::assertFalse($record->authorizes('anything.example.com'));
    }

    #[Test]
    public function aRecordFromBeforeTheHostSetIsKeptButAuthorisesNothing(): void
    {
        $document = $this->document();
        unset($document['license_domains'], $document['license_max_domains']);

        $record = ServiceRecord::fromDocument($document);

        self::assertNotNull($record, 'it is authentic and must not be thrown away');
        self::assertTrue($record->predatesDomainSet());
        self::assertSame([], $record->authorizedDomains());
        self::assertNull($record->maxDomains);
        self::assertFalse($record->authorizes('example.com'), 'the host it names is not an authorisation');
    }

    #[Test]
    public function theHostSetFieldsAreNamedInTheCanonicalFieldList(): void
    {
        // The list documents what the issuer signs; leaving the new fields out of
        // it would be a quiet disagreement about the signed shape.
        self::assertContains('license_domains', ServiceRecord::CANONICAL_FIELDS);
        self::assertContains('license_max_domains', ServiceRecord::CANONICAL_FIELDS);
    }
}
