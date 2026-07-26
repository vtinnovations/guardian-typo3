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
 * Creates named {@see LockInterface} instances. A factory (rather than a single
 * injected lock) lets different concerns — scheduled backup, update job — hold
 * independent, named locks that live under Guardian's working directory.
 */
interface LockFactoryInterface
{
    /**
     * @param string $name         A short, filesystem-safe lock name (validated by the implementation).
     * @param int    $staleSeconds Age after which an abandoned lock is considered stale.
     */
    public function create(string $name, int $staleSeconds = 1800): LockInterface;
}
