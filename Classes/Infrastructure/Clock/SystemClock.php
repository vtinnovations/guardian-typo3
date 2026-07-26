<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Clock;

use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;

/**
 * The production clock: returns the real current time. The only class in the
 * codebase permitted to read the wall clock; everything else takes a
 * {@see ClockInterface} so it stays deterministic under test.
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }
}
