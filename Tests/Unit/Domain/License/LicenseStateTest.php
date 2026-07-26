<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Domain\License;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseState;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseTier;

final class LicenseStateTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-05-04T12:00:00+00:00');
    }

    private function nowMinusDays(int $days): int
    {
        return $this->now->getTimestamp() - ($days * 86400);
    }

    #[Test]
    public function unlicensedStateIsNeverLicensed(): void
    {
        $state = LicenseState::unlicensed();

        self::assertFalse($state->isLicensed($this->now));
        self::assertFalse($state->isPro($this->now));
        self::assertSame(LicenseTier::None, $state->effectiveTier($this->now));
    }

    #[Test]
    public function freshlyVerifiedProKeyUnlocksPro(): void
    {
        $state = LicenseState::fromArray([
            'license_key' => 'ABC-123',
            'license_verified_at' => $this->nowMinusDays(1),
            'license_expires_at' => null,
            'license_domain' => 'example.com',
            'license_package' => 'pro',
        ]);

        self::assertTrue($state->isLicensed($this->now));
        self::assertTrue($state->isPro($this->now));
        self::assertSame(LicenseTier::Pro, $state->effectiveTier($this->now));
    }

    #[Test]
    public function oldVerificationStaysLicensedUntilTheStoredExpiry(): void
    {
        // Validity now derives from the stored dates, not a re-verification window:
        // a license verified long ago with a future expiry keeps working offline.
        $state = LicenseState::fromArray([
            'license_key' => 'ABC-123',
            'license_verified_at' => $this->nowMinusDays(90),
            'license_issued_at' => $this->nowMinusDays(120),
            'license_expires_at' => $this->now->getTimestamp() + (30 * 86400),
            'license_package' => 'pro',
        ]);

        self::assertTrue($state->isLicensed($this->now));
        self::assertTrue($state->isPro($this->now));
        self::assertSame(LicenseTier::Pro, $state->effectiveTier($this->now));
    }

    #[Test]
    public function lifetimeLicenseWithoutExpiryStaysLicensedOffline(): void
    {
        $state = LicenseState::fromArray([
            'license_key' => 'ABC-123',
            'license_verified_at' => $this->nowMinusDays(400),
            'license_expires_at' => null,
            'license_package' => 'pro',
        ]);

        self::assertTrue($state->isLicensed($this->now));
    }

    #[Test]
    public function expiredKeyIsNotLicensedEvenIfRecentlyVerified(): void
    {
        $state = LicenseState::fromArray([
            'license_key' => 'ABC-123',
            'license_verified_at' => $this->nowMinusDays(1),
            'license_expires_at' => $this->nowMinusDays(1),
            'license_package' => 'pro',
        ]);

        self::assertFalse($state->isLicensed($this->now));
        self::assertTrue($state->isExpired($this->now));
    }

    #[Test]
    public function licenseBeforeItsStartDateIsNotYetLicensed(): void
    {
        $state = LicenseState::fromArray([
            'license_key' => 'ABC-123',
            'license_verified_at' => $this->nowMinusDays(1),
            'license_issued_at' => $this->now->getTimestamp() + (2 * 86400),
            'license_package' => 'pro',
        ]);

        self::assertFalse($state->hasStarted($this->now));
        self::assertFalse($state->isLicensed($this->now));
    }

    #[Test]
    public function issuedAndExpiryDatesRoundTripThroughTheStore(): void
    {
        $data = [
            'license_key' => 'ABC-123',
            'license_verified_at' => $this->nowMinusDays(1),
            'license_issued_at' => $this->nowMinusDays(10),
            'license_expires_at' => $this->now->getTimestamp() + 86400,
            'license_domain' => 'example.com',
            'license_package' => 'pro',
            'validation_status' => 'valid',
        ];
        $state = LicenseState::fromArray($data);

        self::assertSame($this->nowMinusDays(10), $state->issuedAt);
        self::assertSame($data['license_expires_at'], $state->expiresAt);
        self::assertSame($this->nowMinusDays(10), LicenseState::fromArray($state->toArray())->issuedAt);
    }

    #[Test]
    public function freeKeyIsLicensedButNotPro(): void
    {
        $state = LicenseState::fromArray([
            'license_key' => 'FREE-1',
            'license_verified_at' => $this->nowMinusDays(2),
            'license_package' => 'free',
        ]);

        self::assertTrue($state->isLicensed($this->now));
        self::assertFalse($state->isPro($this->now));
        self::assertSame(LicenseTier::Free, $state->effectiveTier($this->now));
    }

    #[Test]
    public function neverVerifiedKeyIsNotLicensed(): void
    {
        $state = LicenseState::fromArray([
            'license_key' => 'ABC-123',
            'license_verified_at' => 0,
            'license_package' => 'pro',
        ]);

        self::assertFalse($state->isLicensed($this->now));
    }

    #[Test]
    public function canonicalSchemaIsEmittedWithVersionedIdentityAndAllDateFields(): void
    {
        $state = new LicenseState(
            key: 'ABC-123',
            verifiedAt: $this->nowMinusDays(1),
            expiresAt: $this->now->getTimestamp() + 86400,
            domain: 'example.com',
            package: 'pro',
            validationStatus: LicenseValidationStatus::Valid,
            issuedAt: $this->nowMinusDays(30),
            features: ['recovery'],
            startsAt: $this->nowMinusDays(10),
            licenseVersion: 3,
            signature: 'SIG==',
        );
        $array = $state->toArray();

        self::assertSame(2, $array['schema_version']);
        self::assertSame('Guardian', $array['project']);
        self::assertSame('guardian', $array['project_slug']);
        self::assertSame($this->nowMinusDays(30), $array['license_issued_at']);
        self::assertSame($this->nowMinusDays(10), $array['license_starts_at']);
        self::assertSame($this->now->getTimestamp() + 86400, $array['license_expires_at']);
        self::assertFalse($array['license_lifetime']);
        self::assertSame(3, $array['license_version']);
        self::assertSame('SIG==', $array['signature']);
        // Verification time is NOT the issue/start date.
        self::assertSame($this->nowMinusDays(1), $array['license_verified_at']);
        self::assertNotSame($array['license_verified_at'], $array['license_issued_at']);
        self::assertNotSame($array['license_verified_at'], $array['license_starts_at']);
    }

    #[Test]
    public function lifetimeLicenseSerialisesZeroExpiryAndAnExplicitTrueFlag(): void
    {
        $state = new LicenseState(
            key: 'LIFE-1',
            verifiedAt: $this->nowMinusDays(1),
            expiresAt: null,
            domain: 'example.com',
            package: 'pro',
            validationStatus: LicenseValidationStatus::Valid,
            lifetime: true,
        );
        $array = $state->toArray();

        self::assertSame(0, $array['license_expires_at']);
        self::assertTrue($array['license_lifetime']);
        self::assertFalse($state->isExpired($this->now));
        self::assertTrue($state->isLicensed($this->now));
        // Round-trips as an explicit lifetime license.
        self::assertTrue(LicenseState::fromArray($array)->lifetime);
    }

    #[Test]
    public function startDateGatesValidityIndependentlyOfTheIssueDate(): void
    {
        // Issued in the past, but does not START until the future → not yet valid.
        $state = new LicenseState(
            key: 'FUTURE-START',
            verifiedAt: $this->nowMinusDays(1),
            expiresAt: null,
            domain: 'example.com',
            package: 'pro',
            validationStatus: LicenseValidationStatus::Valid,
            issuedAt: $this->nowMinusDays(30),
            startsAt: $this->now->getTimestamp() + (2 * 86400),
        );

        self::assertFalse($state->hasStarted($this->now));
        self::assertFalse($state->isLicensed($this->now));
    }

    #[Test]
    public function legacyFileWithNullExpiryMigratesToAnExplicitLifetimeLicense(): void
    {
        // The exact shape of a pre-v2 file (no schema_version, null expiry).
        $state = LicenseState::fromArray([
            'license_key' => 'VGR4L-LEGACY',
            'license_verified_at' => 1_784_880_547,
            'license_issued_at' => 1_784_880_547,
            'license_expires_at' => null,
            'license_domain' => 'brickie-typo3.vrisini.com',
            'license_package' => 'pro',
            'validation_status' => 'valid',
        ]);

        self::assertTrue($state->lifetime, 'a verified legacy key with no expiry becomes lifetime');
        self::assertSame(0, $state->toArray()['license_expires_at']);
        self::assertTrue($state->toArray()['license_lifetime']);
        self::assertSame(2, $state->toArray()['schema_version']);
    }
}
