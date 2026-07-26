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

use Symfony\Component\Process\Process;
use Vtinnovations\GuardianTypo3\Application\Contract\DeferredWorkerSpawnerInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\ComposerEnvironment;

/**
 * Spawns the detached CLI worker that performs Guardian self-maintenance AFTER
 * the current backend response has completed:
 *
 *   <php-cli> vendor/bin/typo3 guardian:self-maintenance:run <jobId>
 *
 * This is the deferred mechanism that lets Guardian disable itself safely: the
 * web request returns immediately and the worker (a separate process) runs the
 * supported deactivation once the request is gone. Only a strict
 * `YYYYMMDD-HHMMSS-xxxxxxxx` job token reaches the command line; nothing else
 * from the request does.
 */
final class SelfMaintenanceSpawner implements DeferredWorkerSpawnerInterface
{
    private const ID_PATTERN = '/^\d{8}-\d{6}-[a-f0-9]{8}$/';

    public function __construct(
        private readonly ComposerEnvironment $composerEnvironment,
    ) {
    }

    /**
     * @throws GuardianException
     */
    public function spawn(string $jobId): void
    {
        if (preg_match(self::ID_PATTERN, $jobId) !== 1) {
            throw new GuardianException('Refusing to spawn self-maintenance for an invalid job id.');
        }
        $php = $this->composerEnvironment->phpBinary();
        if ($php === null) {
            throw new GuardianException('No PHP CLI binary is configured — set it in the Guardian settings first.');
        }
        $console = $this->composerEnvironment->typo3Console();
        if ($console === null) {
            throw new GuardianException('The TYPO3 console binary (vendor/bin/typo3) was not found.');
        }

        $isWindows = stripos(\PHP_OS_FAMILY, 'win') === 0;
        if ($isWindows) {
            $cmd = sprintf('start /B "" %s %s guardian:self-maintenance:run %s > NUL 2>&1', escapeshellarg($php), escapeshellarg($console), escapeshellarg($jobId));
        } else {
            $cmd = sprintf('nohup %s %s guardian:self-maintenance:run %s < /dev/null > /dev/null 2>&1 &', escapeshellarg($php), escapeshellarg($console), escapeshellarg($jobId));
        }

        try {
            $process = Process::fromShellCommandline($cmd);
            $process->setTimeout(null);
            $process->start();
        } catch (\Throwable $e) {
            throw new GuardianException('Could not spawn the self-maintenance worker: ' . $e->getMessage());
        }
    }
}
