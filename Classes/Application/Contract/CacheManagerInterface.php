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
 * Port for flushing TYPO3 caches after an update or restore.
 *
 * Replaces the Contao original's `contao-console cache:clear`. The TYPO3
 * equivalent is the core CacheManager / cache flush, but wiring that into an
 * update pipeline is a later phase. No implementation is registered in Phase 1;
 * the interface fixes the seam.
 */
interface CacheManagerInterface
{
    /**
     * Flush all TYPO3 caches.
     *
     * @throws \Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException when unavailable or on failure.
     */
    public function flushAll(): void;
}
