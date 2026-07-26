<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Domain\Archive;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\Archive\ArchiveEntryValidator;

final class ArchiveEntryValidatorTest extends TestCase
{
    private ArchiveEntryValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ArchiveEntryValidator();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeEntries(): array
    {
        return [
            'posix absolute' => ['/etc/passwd'],
            'parent traversal' => ['../../etc/cron.d/x'],
            'nested traversal' => ['vendor/../../secret'],
            'windows drive' => ['C:\\Windows\\system32'],
            'unc path' => ['\\\\host\\share\\x'],
            'empty' => [''],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function safeEntries(): array
    {
        return [
            'plain file' => ['composer.json'],
            'nested file' => ['vendor/typo3/cms-core/composer.json'],
            'dot in name' => ['config/sites/main/config.yaml'],
            'leading dot file' => ['.gitkeep'],
        ];
    }

    #[Test]
    #[DataProvider('unsafeEntries')]
    public function unsafeEntriesAreRejected(string $entry): void
    {
        self::assertFalse($this->validator->isSafe($entry));
    }

    #[Test]
    #[DataProvider('safeEntries')]
    public function safeEntriesAreAccepted(string $entry): void
    {
        self::assertTrue($this->validator->isSafe($entry));
    }

    #[Test]
    public function unsafeEntriesAreCollectedFromAListing(): void
    {
        $listing = ['ok/file.txt', '../escape', 'also/ok', '/abs'];

        self::assertSame(['../escape', '/abs'], $this->validator->unsafeEntries($listing));
        self::assertFalse($this->validator->allSafe($listing));
    }

    #[Test]
    public function cleanListingIsAllSafe(): void
    {
        self::assertTrue($this->validator->allSafe(['a/b', 'c/d/e.txt']));
    }
}
