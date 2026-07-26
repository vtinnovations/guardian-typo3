<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Lock;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Infrastructure\Lock\FlockLock;

/**
 * Exercises the flock-based lock against a real temp file. It is dependency-free
 * (no TYPO3 bootstrap) but does touch the filesystem, so it lives with the unit
 * suite while genuinely verifying mutual exclusion behaviour.
 */
final class FlockLockTest extends TestCase
{
    private string $lockFile;

    protected function setUp(): void
    {
        $this->lockFile = sys_get_temp_dir() . '/guardian-test-' . bin2hex(random_bytes(6)) . '.lock';
    }

    protected function tearDown(): void
    {
        @unlink($this->lockFile);
    }

    #[Test]
    public function acquireSucceedsThenBlocksASecondHolder(): void
    {
        $first = new FlockLock($this->lockFile);
        $second = new FlockLock($this->lockFile);

        self::assertTrue($first->acquire());
        self::assertTrue($first->isHeld());
        self::assertFalse($second->acquire(), 'second holder must not acquire a held lock');

        $first->release();
        self::assertFalse($first->isHeld());

        self::assertTrue($second->acquire(), 'lock must be re-acquirable after release');
        $second->release();
    }

    #[Test]
    public function staleLockIsReclaimed(): void
    {
        // Simulate an abandoned lock file older than the stale threshold.
        file_put_contents($this->lockFile, "locked_at=old\npid=1\n");
        touch($this->lockFile, time() - 3600);

        $lock = new FlockLock($this->lockFile, staleSeconds: 60);

        self::assertTrue($lock->acquire(), 'a lock older than the stale window must be reclaimable');
        $lock->release();
    }

    #[Test]
    public function releaseWithoutAcquireIsSafe(): void
    {
        $lock = new FlockLock($this->lockFile);
        $lock->release();

        self::assertFalse($lock->isHeld());
    }
}
