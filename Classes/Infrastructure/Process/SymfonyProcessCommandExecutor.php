<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Process;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Vtinnovations\GuardianTypo3\Application\Contract\CommandExecutorInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Process\CommandRequest;
use Vtinnovations\GuardianTypo3\Domain\Process\CommandResult;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\ComposerRuntime;

/**
 * Real command executor built on Symfony Process.
 *
 * Security guarantees:
 *   - The command is always an argv ARRAY (never a shell string), so no argument
 *     can be interpreted as a shell metacharacter — there is no shell involved.
 *   - The environment is the inherited process env plus an explicit overlay (the
 *     request's env allow-map + non-interactive Composer defaults); Composer
 *     authentication in COMPOSER_HOME/auth.json keeps working, and no secret is
 *     ever placed on the command line.
 *   - A timeout is always enforced; a timeout surfaces as a distinct exit code so
 *     callers can classify it.
 *
 * This class is meant to run inside the CLI worker (guardian:update:run). It is
 * intentionally NOT wired as the default executor for web requests.
 */
final class SymfonyProcessCommandExecutor implements CommandExecutorInterface
{
    /** Exit code we report when a process is killed by its timeout. */
    public const EXIT_TIMEOUT = 124;

    /**
     * The Composer runtime is optional so the constructor stays backward
     * compatible; dependency injection (and the standalone kernel) supply it, so
     * every process gets a usable HOME/COMPOSER_HOME.
     */
    public function __construct(
        private readonly ?ComposerRuntime $composerRuntime = null,
    ) {
    }

    public function run(CommandRequest $request): CommandResult
    {
        $process = $this->build($request);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new CommandResult(self::EXIT_TIMEOUT, $process->getOutput(), $process->getErrorOutput());
        } catch (\Throwable $e) {
            throw new GuardianException('Could not run ' . $request->binary() . ': ' . $e->getMessage());
        }

        return new CommandResult($process->getExitCode() ?? -1, $process->getOutput(), $process->getErrorOutput());
    }

    /**
     * Runs the command while streaming each output line to the callback in real
     * time. Used by the job runner to feed the live log.
     *
     * @param callable(string $level, string $line): void $onLine
     */
    public function runStreaming(CommandRequest $request, callable $onLine): CommandResult
    {
        $process = $this->build($request);
        try {
            $process->run(static function (string $type, string $buffer) use ($onLine): void {
                $level = $type === Process::ERR ? 'warning' : 'info';
                foreach (preg_split('/\r?\n/', $buffer) ?: [] as $line) {
                    $line = rtrim($line);
                    if ($line !== '') {
                        $onLine($level, $line);
                    }
                }
            });
        } catch (ProcessTimedOutException) {
            $onLine('error', 'Process exceeded its timeout of ' . $request->timeoutSeconds . 's and was terminated.');

            return new CommandResult(self::EXIT_TIMEOUT, $process->getOutput(), $process->getErrorOutput());
        } catch (\Throwable $e) {
            throw new GuardianException('Could not run ' . $request->binary() . ': ' . $e->getMessage());
        }

        return new CommandResult($process->getExitCode() ?? -1, $process->getOutput(), $process->getErrorOutput());
    }

    public function isAvailable(): bool
    {
        // Symfony Process works without exec()/shell if proc_open is available.
        $disabled = array_map('trim', explode(',', (string) \ini_get('disable_functions')));

        return \function_exists('proc_open') && !\in_array('proc_open', $disabled, true);
    }

    private function build(CommandRequest $request): Process
    {
        $process = new Process(
            $request->arguments,
            $request->workingDirectory,
            $this->environmentFor($request),
            null,
            (float) $request->timeoutSeconds,
        );
        // A generous idle timeout guards against a wedged child that produces no
        // output, without killing a legitimately long, chatty install.
        $process->setIdleTimeout(min(600.0, (float) $request->timeoutSeconds));

        return $process;
    }

    /**
     * Builds the explicit environment OVERLAY passed to the process. Symfony
     * Process merges this with the inherited environment, so PATH, TLS/CA, proxy
     * and real Composer auth are preserved; we only add/override what we must:
     *   - HOME / COMPOSER_HOME / COMPOSER_CACHE_DIR from the private runtime,
     *   - COMPOSER_NO_INTERACTION and the xdebug warning suppression.
     * The request's own env allow-map wins over these defaults.
     *
     * @return array<string, string>
     * @throws GuardianException composer_runtime_directory_unavailable when the runtime cannot be created
     */
    public function environmentFor(CommandRequest $request): array
    {
        $runtimeEnv = $this->composerRuntime?->ensure() ?? [];

        return $request->env + $runtimeEnv + [
            'COMPOSER_NO_INTERACTION' => '1',
            'COMPOSER_DISABLE_XDEBUG_WARN' => '1',
        ];
    }
}
