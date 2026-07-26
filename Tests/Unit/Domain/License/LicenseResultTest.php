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
use Vtinnovations\GuardianTypo3\Domain\License\LicenseResult;

final class LicenseResultTest extends TestCase
{
    #[Test]
    public function okIsFullyValid(): void
    {
        $result = LicenseResult::ok('pro');
        self::assertTrue($result->integrityValid);
        self::assertTrue($result->signatureValid);
        self::assertTrue($result->licenseValid);
        self::assertSame('pro', $result->status);
        self::assertTrue($result->isFullyValid());
    }

    #[Test]
    public function integrityFailureIsInvalidAndReasonFree(): void
    {
        $result = LicenseResult::integrityFailure();
        self::assertFalse($result->integrityValid);
        self::assertFalse($result->licenseValid);
        self::assertSame('invalid', $result->status);
        self::assertFalse($result->isFullyValid());
    }

    #[Test]
    public function anyFailingLayerBreaksFullValidity(): void
    {
        self::assertFalse((new LicenseResult(true, false, true, 'pro'))->isFullyValid());
        self::assertFalse((new LicenseResult(true, true, false, 'expired'))->isFullyValid());
    }
}
