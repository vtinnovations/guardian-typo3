<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Clock;

/**
 * Abstraction over "the current time".
 *
 * Domain services that depend on time (schedule evaluation, license grace
 * windows, lock staleness) take a ClockInterface instead of calling
 * \time()/new \DateTimeImmutable('now') directly, so their logic is fully
 * deterministic and unit-testable without touching the system clock.
 */
interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
