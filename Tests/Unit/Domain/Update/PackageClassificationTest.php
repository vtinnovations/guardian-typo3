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
use Vtinnovations\GuardianTypo3\Domain\Update\PackageClassification;

final class PackageClassificationTest extends TestCase
{
    #[Test]
    public function typo3CoreMetapackageIsCore(): void
    {
        self::assertTrue(PackageClassification::isCore('typo3/cms', 'library'));
        self::assertSame(PackageClassification::Core, PackageClassification::classify('typo3/cms', 'library', false));
    }

    #[Test]
    public function typo3CorePackagesAreClassifiedAsCore(): void
    {
        self::assertTrue(PackageClassification::isCore('typo3/cms-core', 'typo3-cms-framework'));
        self::assertTrue(PackageClassification::isCore('typo3/cms-backend', 'typo3-cms-framework'));
        self::assertSame(PackageClassification::Core, PackageClassification::classify('typo3/cms-backend', 'typo3-cms-framework', false));
    }

    #[Test]
    public function frameworkTypeIsCoreEvenWithoutTheTypo3CmsPrefix(): void
    {
        self::assertTrue(PackageClassification::isCore('vendor/whatever', 'typo3-cms-framework'));
    }

    #[Test]
    public function pathRepositoryPackageIsCustom(): void
    {
        self::assertSame(PackageClassification::Custom, PackageClassification::classify('acme/site', 'typo3-cms-extension', true));
    }

    #[Test]
    public function ordinaryComposerDependencyIsThirdParty(): void
    {
        self::assertFalse(PackageClassification::isCore('psr/log', 'library'));
        self::assertSame(PackageClassification::ThirdParty, PackageClassification::classify('psr/log', 'library', false));
    }

    #[Test]
    public function coreWinsOverPathRepository(): void
    {
        // A core package must never be mislabelled as custom even if it somehow
        // resolves via a path repository.
        self::assertSame(PackageClassification::Core, PackageClassification::classify('typo3/cms-core', 'typo3-cms-framework', true));
    }
}
