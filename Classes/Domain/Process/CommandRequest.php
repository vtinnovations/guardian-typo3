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

use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Immutable, shell-free description of an external command to run.
 *
 * The single most important security property Guardian must preserve from the
 * Contao original is: external commands are built as an argv ARRAY and executed
 * without a shell, so no argument can ever be interpreted as a shell
 * metacharacter. This value object makes that guarantee structural — there is
 * no way to express "a command line string" here, only a validated argv list
 * plus an optional explicit environment allow-map.
 *
 * The object carries no ability to execute itself; running it is the job of a
 * {@see \Vtinnovations\GuardianTypo3\Application\Contract\CommandExecutorInterface}
 * implementation, which is a later phase.
 */
final class CommandRequest
{
    /**
     * @param list<string>          $arguments  argv, element 0 is the binary
     * @param array<string, string> $env        explicit env allow-map (never the full ambient env)
     */
    private function __construct(
        public readonly array $arguments,
        public readonly ?string $workingDirectory,
        public readonly int $timeoutSeconds,
        public readonly array $env,
    ) {
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $env
     */
    public static function create(
        array $arguments,
        ?string $workingDirectory = null,
        int $timeoutSeconds = 600,
        array $env = [],
    ): self {
        $arguments = array_values($arguments);

        if ($arguments === []) {
            throw new GuardianException('A command requires at least the binary as argv[0].');
        }

        foreach ($arguments as $index => $argument) {
            if (!\is_string($argument)) {
                throw new GuardianException(sprintf('Command argument #%d is not a string.', $index));
            }
            // NUL bytes cannot appear in a valid argv element and are a classic
            // truncation/injection vector; reject them defensively.
            if (str_contains($argument, "\0")) {
                throw new GuardianException(sprintf('Command argument #%d contains a NUL byte.', $index));
            }
        }

        if (trim($arguments[0]) === '') {
            throw new GuardianException('The command binary (argv[0]) must not be empty.');
        }

        if ($timeoutSeconds < 1) {
            throw new GuardianException('Command timeout must be a positive number of seconds.');
        }

        $cleanEnv = [];
        foreach ($env as $name => $value) {
            $cleanEnv[(string) $name] = (string) $value;
        }

        return new self($arguments, $workingDirectory, $timeoutSeconds, $cleanEnv);
    }

    public function binary(): string
    {
        return $this->arguments[0];
    }

    /**
     * A human-readable, non-executable rendering for logs/UI. This is for
     * display only and must never be fed back to a shell.
     */
    public function describe(): string
    {
        return implode(' ', array_map(
            static fn (string $arg): string => str_contains($arg, ' ') ? '"' . $arg . '"' : $arg,
            $this->arguments
        ));
    }

    public function withEnv(string $name, string $value): self
    {
        return new self(
            $this->arguments,
            $this->workingDirectory,
            $this->timeoutSeconds,
            [$name => $value] + $this->env,
        );
    }
}
