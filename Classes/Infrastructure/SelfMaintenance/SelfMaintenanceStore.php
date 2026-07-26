<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\SelfMaintenance;

use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;

/**
 * Persists the single Guardian self-maintenance job, its live status and its log
 * under a FIXED directory OUTSIDE the Guardian package:
 *
 *   <project>/var/guardian/self-maintenance/{job.json,status.json,log.txt,recovery.json}
 *
 * There are no arbitrary paths and no request-supplied executable content: the
 * directory is derived only from the Guardian working directory, and the only
 * data written are small JSON descriptors + a plain-text log. Because it lives
 * outside the package directory, the status and rollback instructions survive a
 * Guardian removal.
 */
final class SelfMaintenanceStore
{
    private const DIR = 'self-maintenance';

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    public function directory(): string
    {
        $dir = $this->workingDirectory->resolve(self::DIR);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o750, true);
        }

        return $dir;
    }

    /**
     * @param array<string, mixed> $job
     */
    public function saveJob(array $job): void
    {
        $this->writeJson('job.json', $job);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function job(): ?array
    {
        return $this->readJson('job.json');
    }

    /**
     * @param array<string, mixed> $status
     */
    public function saveStatus(array $status): void
    {
        $this->writeJson('status.json', $status);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function status(): ?array
    {
        return $this->readJson('status.json');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function writeRecoveryMetadata(array $metadata): void
    {
        $this->writeJson('recovery.json', $metadata);
    }

    public function appendLog(string $level, string $message): void
    {
        $line = '[' . gmdate('c') . '] [' . $level . '] ' . $message . "\n";
        @file_put_contents($this->directory() . '/log.txt', $line, \FILE_APPEND | \LOCK_EX);
        @chmod($this->directory() . '/log.txt', 0o640);
    }

    public function readLog(): string
    {
        $file = $this->directory() . '/log.txt';

        return is_file($file) ? (string) @file_get_contents($file) : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $name, array $data): void
    {
        @file_put_contents($this->directory() . '/' . $name, json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), \LOCK_EX);
        @chmod($this->directory() . '/' . $name, 0o640);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $name): ?array
    {
        $file = $this->directory() . '/' . $name;
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return \is_array($data) ? $data : null;
    }
}
