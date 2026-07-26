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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Recovery\PanelFilename;

final class PanelFilenameTest extends TestCase
{
    #[Test]
    public function defaultFilenameIsValid(): void
    {
        self::assertTrue(PanelFilename::isValid(PanelFilename::DEFAULT));
        self::assertSame('_guardian-recovery.php', PanelFilename::fromString(PanelFilename::DEFAULT)->value);
    }

    #[Test]
    #[DataProvider('validNames')]
    public function acceptsSafeBasenames(string $name): void
    {
        self::assertTrue(PanelFilename::isValid($name));
    }

    /** @return array<string, array{string}> */
    public static function validNames(): array
    {
        return [
            'default' => ['_guardian-recovery.php'],
            'letters' => ['recovery.php'],
            'mixed' => ['My-Recovery_9.php'],
        ];
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function rejectsUnsafeOrReservedNames(string $name): void
    {
        self::assertFalse(PanelFilename::isValid($name));
        $this->expectException(GuardianException::class);
        PanelFilename::fromString($name);
    }

    /** @return array<string, array{string}> */
    public static function invalidNames(): array
    {
        return [
            'empty' => [''],
            'no extension' => ['recovery'],
            'wrong extension' => ['recovery.phtml'],
            'traversal' => ['../recovery.php'],
            'subdir' => ['sub/recovery.php'],
            'backslash' => ['sub\\recovery.php'],
            'null byte' => ["recovery\0.php"],
            'dotdot' => ['re..covery.php'],
            'space' => ['re covery.php'],
            'reserved index' => ['index.php'],
            'reserved install' => ['install.php'],
            'reserved htaccess' => ['.htaccess'],
        ];
    }
}
