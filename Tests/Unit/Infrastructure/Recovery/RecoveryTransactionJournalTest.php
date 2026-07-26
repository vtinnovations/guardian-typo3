<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\RecoveryTransactionJournal;

final class RecoveryTransactionJournalTest extends TestCase
{
    private RecoveryTransactionJournal $journal;
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/guardian-journal-' . bin2hex(random_bytes(6));
        $this->journal = new RecoveryTransactionJournal(new FakeWorkingDirectory($this->base));
    }

    #[Test]
    public function beginCreatesJournalAndUpdateIsAtomic(): void
    {
        $this->journal->begin('20260723-101010-aabbccdd', ['backup_id' => 'b1', 'vendor_strategy' => 'rebuild']);
        $data = $this->journal->get('20260723-101010-aabbccdd');
        self::assertNotNull($data);
        self::assertSame(RecoveryTransactionJournal::STATE_IN_PROGRESS, $data['state']);

        $this->journal->update('20260723-101010-aabbccdd', ['step' => 'vendor_rebuild', 'old_vendor_path' => 'old']);
        $data = $this->journal->get('20260723-101010-aabbccdd');
        self::assertSame('vendor_rebuild', $data['step']);
        self::assertSame('old', $data['old_vendor_path']);
    }

    #[Test]
    public function inProgressJournalIsDetectedAsIncomplete(): void
    {
        $this->journal->begin('20260723-101010-deadbeef', ['backup_id' => 'b1']);
        $incomplete = $this->journal->findIncomplete();
        self::assertCount(1, $incomplete);
        self::assertSame('20260723-101010-deadbeef', $incomplete[0]['job_id']);
    }

    #[Test]
    public function completedJournalIsNotIncomplete(): void
    {
        $this->journal->begin('20260723-101010-cafebabe', ['backup_id' => 'b1']);
        $this->journal->update('20260723-101010-cafebabe', ['state' => RecoveryTransactionJournal::STATE_COMPLETED]);
        self::assertTrue($this->journal->isTerminal('20260723-101010-cafebabe'));
        self::assertSame([], $this->journal->findIncomplete());
    }

    #[Test]
    public function rejectsPathTraversalJobId(): void
    {
        $this->expectException(GuardianException::class);
        $this->journal->dir('../../etc');
    }
}
