<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Domain\Recovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\Recovery\VendorRestoreStrategy;

final class VendorRestoreStrategyTest extends TestCase
{
    #[Test]
    public function defaultsToRebuildForUnknownOrEmpty(): void
    {
        self::assertSame(VendorRestoreStrategy::Rebuild, VendorRestoreStrategy::fromString(null));
        self::assertSame(VendorRestoreStrategy::Rebuild, VendorRestoreStrategy::fromString('nonsense'));
        self::assertSame(VendorRestoreStrategy::Rebuild, VendorRestoreStrategy::fromString('rebuild'));
    }

    #[Test]
    public function mapsArchivedAndSkip(): void
    {
        self::assertSame(VendorRestoreStrategy::Archived, VendorRestoreStrategy::fromString('archived'));
        self::assertSame(VendorRestoreStrategy::Skip, VendorRestoreStrategy::fromString('skip'));
    }

    #[Test]
    public function onlyArchivedUsesTheArchive(): void
    {
        self::assertTrue(VendorRestoreStrategy::Archived->usesArchive());
        self::assertFalse(VendorRestoreStrategy::Rebuild->usesArchive());
        self::assertFalse(VendorRestoreStrategy::Skip->usesArchive());
    }

    #[Test]
    public function skipDoesNotTouchVendor(): void
    {
        self::assertFalse(VendorRestoreStrategy::Skip->touchesVendor());
        self::assertTrue(VendorRestoreStrategy::Rebuild->touchesVendor());
        self::assertTrue(VendorRestoreStrategy::Archived->touchesVendor());
    }
}
