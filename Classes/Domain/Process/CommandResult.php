<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Process;

/**
 * Immutable outcome of an executed {@see CommandRequest}. Models exit code and
 * captured output so future command-execution code has a stable, typed return
 * value instead of loose by-ref arrays (as the audited Contao code used).
 */
final class CommandResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout = '',
        public readonly string $stderr = '',
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }

    public function combinedOutput(): string
    {
        return trim($this->stdout . "\n" . $this->stderr);
    }
}
