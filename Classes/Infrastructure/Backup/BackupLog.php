<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Backup;

/**
 * Collects the per-backup log lines in memory and flushes them to the backup's
 * `<id>.log` file. Kept small and dependency-free; it never records secrets
 * (callers pass non-sensitive progress lines only).
 */
final class BackupLog
{
    /** @var list<string> */
    private array $lines = [];

    public function add(string $line): void
    {
        $this->lines[] = '[' . gmdate('H:i:s') . '] ' . $line;
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function toString(): string
    {
        return implode("\n", $this->lines) . "\n";
    }

    public function writeTo(string $file, int $mode): void
    {
        if (@file_put_contents($file, $this->toString(), \LOCK_EX) !== false) {
            @chmod($file, $mode);
        }
    }
}
