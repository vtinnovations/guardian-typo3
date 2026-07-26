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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Update\PackageName;

final class PackageNameTest extends TestCase
{
    #[Test]
    #[DataProvider('validNames')]
    public function acceptsValidComposerNames(string $name): void
    {
        self::assertTrue(PackageName::isValid($name));
        self::assertSame($name, PackageName::fromString($name)->value);
    }

    /** @return array<string, array{string}> */
    public static function validNames(): array
    {
        return [
            'typo3 core' => ['typo3/cms-core'],
            'vendor pkg' => ['vtinnovations/guardian-typo3'],
            'dots' => ['phpunit/php-code-coverage'],
        ];
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function rejectsInvalidOrDangerousNames(string $name): void
    {
        self::assertFalse(PackageName::isValid($name));
        $this->expectException(GuardianException::class);
        PackageName::fromString($name);
    }

    /** @return array<string, array{string}> */
    public static function invalidNames(): array
    {
        return [
            'empty' => [''],
            'no slash' => ['typo3'],
            'leading dash (flag injection)' => ['-rf'],
            'flag' => ['--no-scripts'],
            'space' => ['typo3/cms core'],
            'null byte' => ["typo3/cms\0core"],
            'shell metachar' => ['typo3/cms;rm'],
            'uppercase disallowed' => ['TYPO3/Cms-Core'],
        ];
    }

    #[Test]
    public function validateListRejectsTheWholeListOnAnyBadEntry(): void
    {
        $this->expectException(GuardianException::class);
        PackageName::validateList(['typo3/cms-core', '--dry-run']);
    }

    #[Test]
    public function validateListDeduplicates(): void
    {
        self::assertSame(['typo3/cms-core'], PackageName::validateList(['typo3/cms-core', 'typo3/cms-core']));
    }
}
