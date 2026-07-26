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

use Symfony\Component\Process\Process;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Spawns the detached CLI worker that actually runs an update job:
 *
 *   <php-cli> vendor/bin/typo3 guardian:update:run <jobId>
 *
 * The web request starts the worker and returns immediately; the browser then
 * polls job status. Only the job ID is interpolated into the background command,
 * and it is a strict `YYYYMMDD-HHMMSS-xxxxxxxx` token validated here — no other
 * user input reaches the command line. (Composer/TYPO3 commands themselves run
 * as argv arrays with no shell; this detached launcher is the one place a
 * background `&` is required, and it carries only a validated token.)
 */
final class UpdateWorkerSpawner
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
            throw new GuardianException('Refusing to spawn a worker for an invalid job id.');
        }
        $php = $this->composerEnvironment->phpBinary();
        if ($php === null) {
            throw new GuardianException('No PHP CLI binary is configured — set it in the Guardian settings before updating.');
        }
        $console = $this->composerEnvironment->typo3Console();
        if ($console === null) {
            throw new GuardianException('The TYPO3 console binary (vendor/bin/typo3) was not found.');
        }

        $isWindows = stripos(\PHP_OS_FAMILY, 'win') === 0;
        if ($isWindows) {
            $cmd = sprintf(
                'start /B "" %s %s guardian:update:run %s > NUL 2>&1',
                escapeshellarg($php),
                escapeshellarg($console),
                escapeshellarg($jobId),
            );
        } else {
            $cmd = sprintf(
                'nohup %s %s guardian:update:run %s < /dev/null > /dev/null 2>&1 &',
                escapeshellarg($php),
                escapeshellarg($console),
                escapeshellarg($jobId),
            );
        }

        try {
            $process = Process::fromShellCommandline($cmd);
            $process->setTimeout(null);
            $process->start();
        } catch (\Throwable $e) {
            throw new GuardianException('Could not spawn the update worker: ' . $e->getMessage());
        }
    }
}
