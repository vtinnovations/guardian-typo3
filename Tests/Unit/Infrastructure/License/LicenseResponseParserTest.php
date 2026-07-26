<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\License;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseVerificationStatus;
use Vtinnovations\GuardianTypo3\Infrastructure\License\LicenseResponseParser;

final class LicenseResponseParserTest extends TestCase
{
    private LicenseResponseParser $parser;

    protected function setUp(): void
    {
        $this->parser = new LicenseResponseParser();
    }

    #[Test]
    public function parsesEveryLicenseFactAsThreeDistinctDates(): void
    {
        $result = $this->parser->parse([
            'valid' => true,
            'package' => ' PRO ',
            'issued_at' => 1000,
            'starts_at' => 2000,
            'expires_at' => 3000,
            'license_version' => 4,
            'features' => ['recovery', 'scheduled_backups', ''],
            'signature' => 'SIG==',
            'message' => 'ok',
        ]);

        self::assertSame(LicenseVerificationStatus::Valid, $result->status);
        self::assertSame('pro', $result->package);
        self::assertSame(1000, $result->issuedAt);
        self::assertSame(2000, $result->startsAt);
        self::assertSame(3000, $result->expiresAt);
        self::assertFalse($result->lifetime);
        self::assertSame(4, $result->licenseVersion);
        self::assertSame(['recovery', 'scheduled_backups'], $result->features);
        self::assertSame('SIG==', $result->signature);
    }

    #[Test]
    public function anExplicitLifetimeFlagClearsTheExpiry(): void
    {
        $result = $this->parser->parse(['valid' => true, 'package' => 'pro', 'lifetime' => true, 'expires_at' => 9999]);

        self::assertTrue($result->lifetime);
        self::assertNull($result->expiresAt);
    }

    #[Test]
    public function aNullOrZeroExpiryIsTreatedAsLifetime(): void
    {
        self::assertTrue($this->parser->parse(['valid' => true, 'package' => 'pro', 'expires_at' => null])->lifetime);
        self::assertTrue($this->parser->parse(['valid' => true, 'package' => 'pro', 'expires_at' => 0])->lifetime);
    }

    #[Test]
    public function anOmittedExpiryIsNotAssumedToBeLifetime(): void
    {
        // Neither an expiry nor a lifetime signal: stay conservative (not lifetime).
        $result = $this->parser->parse(['valid' => true, 'package' => 'pro']);
        self::assertFalse($result->lifetime);
        self::assertNull($result->expiresAt);
    }

    #[Test]
    public function deniedAndMalformedResponsesMapToTheRightStatus(): void
    {
        self::assertSame(LicenseVerificationStatus::Denied, $this->parser->parse(['valid' => false, 'message' => 'no'])->status);
        self::assertSame(LicenseVerificationStatus::Unreachable, $this->parser->parse(['garbage' => 1])->status);
        self::assertSame(LicenseVerificationStatus::Unreachable, $this->parser->parse('not-json')->status);
    }

    #[Test]
    public function toleratesAlternativeFieldNames(): void
    {
        $result = $this->parser->parse([
            'valid' => true,
            'package' => 'pro',
            'license_issued_at' => 111,
            'valid_from' => 222,
            'license_expires_at' => 333,
            'version' => 7,
            'entitlements' => ['x'],
        ]);

        self::assertSame(111, $result->issuedAt);
        self::assertSame(222, $result->startsAt);
        self::assertSame(333, $result->expiresAt);
        self::assertSame(7, $result->licenseVersion);
        self::assertSame(['x'], $result->features);
    }
}
