<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Update;

use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;
use Vtinnovations\GuardianTypo3\Domain\Job\Job;

/**
 * Persists the single active update job (var/guardian/update/job.json) and the
 * archive of finished jobs (var/guardian/update/jobs/<id>.json). Only one job is
 * ever active at a time, by design — concurrent updates are never allowed.
 *
 * Ported from the storage half of the audited Contao UpdateJobManager. The
 * spawn/worker logic lives elsewhere; this class only reads and writes state.
 */
final class UpdateJobStore
{
    private const ACTIVE = 'update/job.json';
    private const ARCHIVE_DIR = 'update/jobs';
    private const ID_PATTERN = '/^\d{8}-\d{6}-[a-f0-9]{8}$/';

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
        private readonly ClockInterface $clock,
    ) {
    }

    public function current(): ?Job
    {
        $file = $this->activeFile();
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return \is_array($data) && ($data['id'] ?? '') !== '' ? Job::fromArray($data) : null;
    }

    public function save(Job $job): void
    {
        $file = $this->activeFile();
        $dir = \dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o750, true);
        }
        @file_put_contents($file, json_encode($job->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), \LOCK_EX);
        @chmod($file, 0o640);
    }

    /**
     * Archives a finished job and clears the active slot.
     */
    public function archive(Job $job): void
    {
        if (!$job->isFinished()) {
            return;
        }
        $dir = $this->workingDirectory->resolve(self::ARCHIVE_DIR);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o750, true);
        }
        if (preg_match(self::ID_PATTERN, $job->id) === 1) {
            @file_put_contents($dir . '/' . $job->id . '.json', json_encode($job->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
            @chmod($dir . '/' . $job->id . '.json', 0o640);
        }
        $active = $this->activeFile();
        if (is_file($active)) {
            @unlink($active);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listArchive(int $limit = 20): array
    {
        $dir = $this->workingDirectory->resolve(self::ARCHIVE_DIR);
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.json') ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $files = \array_slice($files, 0, max(1, $limit));

        $out = [];
        foreach ($files as $f) {
            $data = json_decode((string) @file_get_contents($f), true);
            if (\is_array($data)) {
                $out[] = $data;
            }
        }

        return $out;
    }

    public function getArchived(string $jobId): ?Job
    {
        if (preg_match(self::ID_PATTERN, $jobId) !== 1) {
            return null;
        }
        $file = $this->workingDirectory->resolve(self::ARCHIVE_DIR) . '/' . $jobId . '.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return \is_array($data) ? Job::fromArray($data) : null;
    }

    public function generateId(): string
    {
        return $this->clock->now()->format('Ymd-His') . '-' . bin2hex(random_bytes(4));
    }

    /**
     * Heuristic stale detection: queued too long, or running with a log that has
     * not been touched for a long time.
     */
    public function isStale(Job $job, int $queuedTimeoutSeconds = 120, int $idleTimeoutSeconds = 1800): bool
    {
        if ($job->isFinished()) {
            return false;
        }
        $now = $this->clock->now()->getTimestamp();
        if ($job->status->value === 'queued') {
            $created = $job->createdAt?->getTimestamp() ?? 0;

            return $created > 0 && ($now - $created) > $queuedTimeoutSeconds;
        }
        $mtime = (int) @filemtime($this->activeFile());

        return $mtime > 0 && ($now - $mtime) > $idleTimeoutSeconds;
    }

    private function activeFile(): string
    {
        return $this->workingDirectory->resolve(self::ACTIVE);
    }
}
