<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Domain\Update;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\Update\PackageStatus;

final class PackageStatusTest extends TestCase
{
    #[Test]
    public function classifiesMajorMinorPatchAndCurrent(): void
    {
        self::assertSame(PackageStatus::MajorAvailable, PackageStatus::classify('12.4.0', '13.0.0'));
        self::assertSame(PackageStatus::MinorAvailable, PackageStatus::classify('13.4.0', '13.5.0'));
        self::assertSame(PackageStatus::PatchAvailable, PackageStatus::classify('13.4.9', '13.4.12'));
        self::assertSame(PackageStatus::Current, PackageStatus::classify('13.4.9', '13.4.9'));
    }

    #[Test]
    public function installedAheadOfLatestIsCurrent(): void
    {
        self::assertSame(PackageStatus::Current, PackageStatus::classify('14.0.0', '13.9.9'));
    }

    #[Test]
    public function abandonedAlwaysWins(): void
    {
        self::assertSame(PackageStatus::Abandoned, PackageStatus::classify('1.0.0', '2.0.0', true));
    }

    #[Test]
    public function missingVersionsAreUnknown(): void
    {
        self::assertSame(PackageStatus::Unknown, PackageStatus::classify('13.4.9', ''));
        self::assertSame(PackageStatus::Unknown, PackageStatus::classify('', '13.4.9'));
    }

    #[Test]
    public function toleratesVPrefixAndPreReleaseSuffix(): void
    {
        self::assertSame(PackageStatus::MinorAvailable, PackageStatus::classify('v13.4.0', '13.5.0-RC1'));
    }

    #[Test]
    public function hasUpdateReflectsAvailability(): void
    {
        self::assertTrue(PackageStatus::MajorAvailable->hasUpdate());
        self::assertTrue(PackageStatus::PatchAvailable->hasUpdate());
        self::assertFalse(PackageStatus::Current->hasUpdate());
        self::assertFalse(PackageStatus::Abandoned->hasUpdate());
    }
}
