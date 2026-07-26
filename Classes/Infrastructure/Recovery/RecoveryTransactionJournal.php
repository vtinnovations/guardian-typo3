<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Recovery;

use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Persistent, crash-safe recovery transaction journal.
 *
 * Before ANY destructive step, a recovery writes
 * `var/guardian/recovery/<job-id>/transaction.json`. It is updated atomically
 * (temp file + rename) after every destructive step, so if the process crashes,
 * is interrupted or times out, the next panel load can detect the incomplete
 * transaction, refuse to start a new recovery over it, and offer a safe rollback.
 *
 * The journal records only non-sensitive bookkeeping — job/backup IDs, selected
 * components, the vendor strategy, moved/created/pending paths, and the state of
 * the database, maintenance mode and rollback. It never stores secrets.
 */
final class RecoveryTransactionJournal
{
    public const STATE_IN_PROGRESS = 'in_progress';
    public const STATE_COMPLETED = 'completed';
    public const STATE_ROLLED_BACK = 'rolled_back';
    public const STATE_ROLLBACK_FAILED = 'rollback_failed';
    public const STATE_FAILED = 'failed';

    private const TERMINAL = [self::STATE_COMPLETED, self::STATE_ROLLED_BACK];

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    public function jobId(string $jobId): string
    {
        if (preg_match('#^[A-Za-z0-9._-]{1,64}$#', $jobId) !== 1) {
            throw new GuardianException('Invalid recovery job id.');
        }

        return $jobId;
    }

    public function dir(string $jobId): string
    {
        return $this->workingDirectory->resolve('recovery/' . $this->jobId($jobId));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function begin(string $jobId, array $data): void
    {
        $dir = $this->dir($jobId);
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new GuardianException('Could not create the recovery working directory.');
        }
        $this->write($jobId, array_merge([
            'job_id' => $jobId,
            'state' => self::STATE_IN_PROGRESS,
            'step' => 'begin',
            'started_at' => gmdate('c'),
            'paths_moved' => [],
            'paths_created' => [],
            'paths_pending_deletion' => [],
            'database_restored' => false,
            'maintenance_previous' => null,
            'old_vendor_path' => null,
            'new_vendor_path' => null,
            'safety_snapshot_id' => null,
            'rollback_state' => 'none',
        ], $data));
    }

    /**
     * Atomically merges a patch into the journal.
     *
     * @param array<string, mixed> $patch
     */
    public function update(string $jobId, array $patch): void
    {
        $current = $this->get($jobId) ?? ['job_id' => $jobId];
        $this->write($jobId, array_merge($current, $patch, ['updated_at' => gmdate('c')]));
    }

    public function step(string $jobId, string $step): void
    {
        $this->update($jobId, ['step' => $step]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $jobId): ?array
    {
        $file = $this->file($jobId);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return \is_array($data) ? $data : null;
    }

    public function isTerminal(string $jobId): bool
    {
        $data = $this->get($jobId);

        return $data !== null && \in_array((string) ($data['state'] ?? ''), self::TERMINAL, true);
    }

    /**
     * Scans for interrupted recoveries (a journal that never reached a terminal
     * state). These block a new recovery until rolled back.
     *
     * @return list<array<string, mixed>>
     */
    public function findIncomplete(): array
    {
        $base = $this->workingDirectory->resolve('recovery');
        if (!is_dir($base)) {
            return [];
        }
        $out = [];
        foreach (glob($base . '/*/transaction.json') ?: [] as $file) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (\is_array($data) && !\in_array((string) ($data['state'] ?? ''), self::TERMINAL, true)) {
                $out[] = $data;
            }
        }

        return $out;
    }

    private function file(string $jobId): string
    {
        return $this->dir($jobId) . '/transaction.json';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(string $jobId, array $data): void
    {
        $file = $this->file($jobId);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($tmp, $json, \LOCK_EX) === false) {
            throw new GuardianException('Could not write the recovery transaction journal.');
        }
        @chmod($tmp, 0o640);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new GuardianException('Could not commit the recovery transaction journal.');
        }
    }
}
