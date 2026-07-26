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
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Update\PackageRequirement;

final class PackageRequirementTest extends TestCase
{
    #[Test]
    public function acceptsABarePackageName(): void
    {
        $req = PackageRequirement::fromString('georgringer/news');
        self::assertSame('georgringer/news', $req->name);
        self::assertNull($req->constraint);
        self::assertSame('georgringer/news', $req->toArgument());
    }

    #[Test]
    public function acceptsANameWithASafeConstraint(): void
    {
        $req = PackageRequirement::fromString('vendor/pkg:^1.2 || ^2.0');
        self::assertSame('vendor/pkg', $req->name);
        self::assertSame('^1.2 || ^2.0', $req->constraint);
        self::assertSame('vendor/pkg:^1.2 || ^2.0', $req->toArgument());
    }

    #[Test]
    public function rejectsAFlagMasqueradingAsAName(): void
    {
        $this->expectException(GuardianException::class);
        PackageRequirement::fromString('--no-scripts');
    }

    #[Test]
    public function rejectsShellMetacharactersInTheConstraint(): void
    {
        $this->expectException(GuardianException::class);
        PackageRequirement::fromString('vendor/pkg:; rm -rf /');
    }

    #[Test]
    public function rejectsNullBytes(): void
    {
        $this->expectException(GuardianException::class);
        PackageRequirement::fromString("vendor/pkg\0");
    }

    #[Test]
    public function validatesAListAndThrowsOnFirstBadEntry(): void
    {
        self::assertSame(['a/b', 'c/d:^1.0'], PackageRequirement::validateList(['a/b', 'c/d:^1.0']));
        $this->expectException(GuardianException::class);
        PackageRequirement::validateList(['a/b', '../evil']);
    }
}
