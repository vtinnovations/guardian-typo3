<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Contract;

/**
 * A mutually exclusive, best-effort lock used to prevent overlapping
 * backup/update runs. The default implementation is filesystem-based (flock),
 * matching the audited Contao BackupLock, but callers depend only on this
 * contract so an alternative (e.g. TYPO3's LockingStrategy) could be swapped in.
 */
interface LockInterface
{
    /**
     * Attempts to acquire the lock without blocking.
     *
     * @return bool True if acquired, false if already held by another process.
     */
    public function acquire(): bool;

    /**
     * Releases the lock if held. Safe to call when not held.
     */
    public function release(): void;

    public function isHeld(): bool;
}
