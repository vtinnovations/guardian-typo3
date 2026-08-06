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

use Vtinnovations\GuardianTypo3\Application\Contract\LockInterface;

final class InMemoryLock implements LockInterface
{
    private bool $held = false;

    public function acquire(): bool
    {
        if ($this->held) {
            return false;
        }

        return $this->held = true;
    }

    public function release(): void
    {
        $this->held = false;
    }

    public function isHeld(): bool
    {
        return $this->held;
    }
}
