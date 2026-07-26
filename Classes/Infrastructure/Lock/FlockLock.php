<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Lock;

use Vtinnovations\GuardianTypo3\Application\Contract\LockInterface;

/**
 * Advisory file lock using a non-blocking flock() on a sentinel file, ported
 * from the audited Contao BackupLock.
 *
 * Correctness properties preserved:
 *   - LOCK_EX|LOCK_NB: never blocks; a busy lock returns false immediately.
 *   - OS-released on process death: if a holder dies, the descriptor closes and
 *     the kernel drops the lock, so it self-heals.
 *   - Stale-age guard: a lock file older than the configured timeout is treated
 *     as abandoned and removed before acquiring, defending against a holder that
 *     died without the OS clearing the file promptly.
 *
 * This is a safe, non-destructive foundation: it only ever touches its own
 * sentinel file inside Guardian's working directory.
 */
final class FlockLock implements LockInterface
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(
        private readonly string $lockFile,
        private readonly int $staleSeconds = 1800,
    ) {
    }

    public function acquire(): bool
    {
        $dir = \dirname($this->lockFile);
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            return false;
        }

        if (is_file($this->lockFile)) {
            $age = time() - (int) @filemtime($this->lockFile);
            if ($age > $this->staleSeconds) {
                @unlink($this->lockFile);
            }
        }

        $handle = @fopen($this->lockFile, 'c');
        if ($handle === false) {
            return false;
        }

        if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
            fclose($handle);

            return false;
        }

        ftruncate($handle, 0);
        fwrite($handle, sprintf("locked_at=%s\npid=%d\n", date(\DATE_ATOM), getmypid() ?: 0));
        fflush($handle);

        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, \LOCK_UN);
        fclose($this->handle);
        $this->handle = null;

        @unlink($this->lockFile);
    }

    public function isHeld(): bool
    {
        return $this->handle !== null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
