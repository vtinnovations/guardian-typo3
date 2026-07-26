<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Application\License;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\License\LicenseStatus;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseState;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseValidationStatus;

/**
 * The activation button label is driven by the SERVER-side `has_key` flag in the
 * license status payload (not by the displayed badge or JS state): false = show
 * "Verify and activate license", true = show "Update License".
 */
final class LicenseStatusTest extends TestCase
{
    #[Test]
    public function noStoredLicenseReportsHasKeyFalseForTheActivationLabel(): void
    {
        $status = new LicenseStatus(LicenseState::unlicensed(), 'none', false, false, false);
        self::assertFalse($status->toPublicArray()['has_key']);
    }

    #[Test]
    public function aStoredLicenseReportsHasKeyTrueForTheUpdateLabel(): void
    {
        $state = new LicenseState('STORED-NOT-REAL', 1_700_000_000, null, 'example.com', 'pro', LicenseValidationStatus::Valid, 1_699_000_000);
        $status = new LicenseStatus($state, 'pro', true, true, false);

        $public = $status->toPublicArray();
        self::assertTrue($public['has_key']);
        // The stored dates and package are exposed for local display.
        self::assertSame(1_699_000_000, $public['issued_at']);
        self::assertSame('pro', $public['plan']);
    }

    #[Test]
    public function activeFreeLicenseAlsoReportsHasKeyTrue(): void
    {
        $state = new LicenseState('FREE-STORED-NOT-REAL', 1_700_000_000, null, 'example.com', 'free', LicenseValidationStatus::Valid);
        $status = new LicenseStatus($state, 'free', true, false, false);

        self::assertTrue($status->toPublicArray()['has_key'], 'an active Free license shows the Update label');
        self::assertSame('free', $status->toPublicArray()['plan']);
    }

    #[Test]
    public function activeProLicenseReportsHasKeyTrue(): void
    {
        $state = new LicenseState('PRO-STORED-NOT-REAL', 1_700_000_000, null, 'example.com', 'pro', LicenseValidationStatus::Valid);
        $status = new LicenseStatus($state, 'pro', true, true, false);

        self::assertTrue($status->toPublicArray()['has_key']);
    }

    #[Test]
    public function theFullStoredKeyIsNeverExposedInThePublicPayload(): void
    {
        $fullKey = 'ABCD-SECRET-FULL-KEY-1234567890-WXYZ';
        $state = new LicenseState($fullKey, 1_700_000_000, null, 'example.com', 'pro', LicenseValidationStatus::Valid);
        $public = (new LicenseStatus($state, 'pro', true, true, false))->toPublicArray();

        self::assertArrayNotHasKey('key', $public, 'the raw key field is not exposed');
        self::assertStringNotContainsString($fullKey, json_encode($public, \JSON_UNESCAPED_SLASHES) ?: '');
        self::assertStringContainsString('•', $public['key_preview']);
        self::assertStringNotContainsString($fullKey, $public['key_preview']);
    }
}
