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

use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;

final class FixedClock implements ClockInterface
{
    public function __construct(private int $timestamp = 1784880547)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $this->timestamp))->setTimezone(new \DateTimeZone('UTC'));
    }

    public function set(int $timestamp): void
    {
        $this->timestamp = $timestamp;
    }

    public function advance(int $seconds): void
    {
        $this->timestamp += $seconds;
    }
}
