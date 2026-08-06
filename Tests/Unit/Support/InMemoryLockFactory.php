<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Support;

use Vtinnovations\GuardianTypo3\Application\Contract\LockFactoryInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\LockInterface;

final class InMemoryLockFactory implements LockFactoryInterface
{
    /** @var array<string, InMemoryLock> */
    private array $locks = [];

    public function create(string $name, int $staleSeconds = 1800): LockInterface
    {
        return $this->locks[$name] ??= new InMemoryLock();
    }
}
