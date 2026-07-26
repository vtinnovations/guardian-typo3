<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Application\Backup;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Backup\RetentionPolicy;

final class RetentionPolicyTest extends TestCase
{
    private RetentionPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new RetentionPolicy();
    }

    /**
     * @return list<array{id: string, type: string}>
     */
    private function backups(): array
    {
        return [
            ['id' => '2026-07-20_03-00-00-aaaaaaaa', 'type' => 'mini'],
            ['id' => '2026-07-21_03-00-00-bbbbbbbb', 'type' => 'mini'],
            ['id' => '2026-07-22_03-00-00-cccccccc', 'type' => 'mini'],
            ['id' => '2026-07-19_02-00-00-dddddddd', 'type' => 'full'],
            ['id' => '2026-07-22_10-00-00-eeeeeeee', 'type' => 'manual'],
        ];
    }

    #[Test]
    public function prunesOldestBeyondKeepLimitForTheGivenTypeOnly(): void
    {
        $toDelete = $this->policy->idsToPrune($this->backups(), 'mini', 2);

        self::assertSame(['2026-07-20_03-00-00-aaaaaaaa'], $toDelete);
    }

    #[Test]
    public function keepingMoreThanExistingDeletesNothing(): void
    {
        self::assertSame([], $this->policy->idsToPrune($this->backups(), 'mini', 10));
        self::assertSame([], $this->policy->idsToPrune($this->backups(), 'full', 1));
    }

    #[Test]
    public function keepIsClampedToAtLeastOne(): void
    {
        $toDelete = $this->policy->idsToPrune($this->backups(), 'mini', 0);

        // Keep the single newest, prune the two older.
        self::assertSame(
            ['2026-07-21_03-00-00-bbbbbbbb', '2026-07-20_03-00-00-aaaaaaaa'],
            $toDelete
        );
    }

    #[Test]
    public function unknownTypeYieldsNothing(): void
    {
        self::assertSame([], $this->policy->idsToPrune($this->backups(), 'nope', 1));
    }
}
