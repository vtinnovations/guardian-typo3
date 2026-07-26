<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Configuration;

use Symfony\Component\Process\PhpExecutableFinder;
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Domain\Process\CommandRequest;
use Vtinnovations\GuardianTypo3\Infrastructure\Process\SymfonyProcessCommandExecutor;

/**
 * Detects available PHP CLI binaries and safely validates + tests a chosen path.
 *
 * A candidate is only ever executed as a strict argv array `[binary, '-v']`
 * through the shell-free {@see SymfonyProcessCommandExecutor} — never a shell,
 * exec(), backticks or string concatenation. Manual input is validated
 * server-side (absolute path, no null byte, no shell syntax / extra arguments,
 * real regular executable file) before execution, with a bounded timeout, and
 * the reported PHP version is checked against Guardian's minimum and the
 * installed TYPO3 version's minimum. All outcomes are language-neutral codes.
 */
final class PhpBinaryInspector
{
    /** Guardian's own minimum supported PHP. */
    public const MIN_PHP = '8.2.0';
    private const TIMEOUT = 10;
    private const MAX_OUTPUT = 4000;

    public function __construct(
        private readonly SymfonyProcessCommandExecutor $executor,
        private readonly ProjectEnvironmentInterface $environment,
        private readonly RuntimeConfigurationService $runtimeConfiguration,
    ) {
    }

    /**
     * @return array{configured: string, guardian_min: string, typo3_min: string, candidates: list<array<string, mixed>>}
     */
    public function detect(): array
    {
        $configured = $this->runtimeConfiguration->current()->phpBinary;
        $seen = [];
        $candidates = [];
        foreach ($this->candidatePaths() as $path) {
            $real = @realpath($path);
            if ($real === false || isset($seen[$real])) {
                continue;
            }
            $seen[$real] = true;
            if (!is_file($real) || !is_executable($real)) {
                continue;
            }
            $candidates[] = $this->test($real);
        }

        return [
            'configured' => $configured,
            'guardian_min' => self::MIN_PHP,
            'typo3_min' => $this->typo3Minimum(),
            'candidates' => $candidates,
        ];
    }

    /**
     * Validate and (when valid) execute `[binary, '-v']` for a single path.
     *
     * @return array{path: string, real_path: ?string, valid: bool, error_code: ?string, executable: bool, version: ?string, satisfies_guardian: bool, satisfies_typo3: bool, guardian_min: string, typo3_min: string, exit_code: ?int, output: ?string}
     */
    public function test(string $path): array
    {
        $result = [
            'path' => $path,
            'real_path' => null,
            'valid' => false,
            'error_code' => null,
            'executable' => false,
            'version' => null,
            'satisfies_guardian' => false,
            'satisfies_typo3' => false,
            'guardian_min' => self::MIN_PHP,
            'typo3_min' => $this->typo3Minimum(),
            'exit_code' => null,
            'output' => null,
        ];

        $error = $this->validatePath($path);
        if ($error !== null) {
            $result['error_code'] = $error;

            return $result;
        }

        $real = (string) realpath($path);
        $result['real_path'] = $real;
        $result['executable'] = true;

        if (!$this->executor->isAvailable()) {
            $result['error_code'] = 'exec_unavailable';

            return $result;
        }

        $run = $this->executor->run(CommandRequest::create([$real, '-v'], null, self::TIMEOUT));
        $result['exit_code'] = $run->exitCode;
        $output = trim($run->stdout . "\n" . $run->stderr);
        $result['output'] = mb_substr($output, 0, self::MAX_OUTPUT);

        if ($run->exitCode === SymfonyProcessCommandExecutor::EXIT_TIMEOUT) {
            $result['error_code'] = 'timeout';

            return $result;
        }
        if (!$run->isSuccessful()) {
            $result['error_code'] = 'not_a_php_binary';

            return $result;
        }

        $version = $this->parseVersion($output);
        if ($version === null) {
            $result['error_code'] = 'version_unreadable';

            return $result;
        }
        $result['version'] = $version;
        $result['satisfies_guardian'] = version_compare($version, self::MIN_PHP, '>=');
        $result['satisfies_typo3'] = version_compare($version, $this->typo3Minimum(), '>=');

        if (!$result['satisfies_guardian']) {
            $result['error_code'] = 'unsupported_version';

            return $result;
        }

        $result['valid'] = true;

        return $result;
    }

    /**
     * @return ?string machine error code, or null when the path is structurally valid
     */
    private function validatePath(string $path): ?string
    {
        if (str_contains($path, "\0")) {
            return 'null_byte';
        }
        if ($path === '' || $path[0] !== '/') {
            return 'not_absolute';
        }
        // No shell syntax, no whitespace (which would smuggle extra arguments),
        // no glob/redirection metacharacters.
        if (preg_match('/[\s;|&$`(){}<>*?!\\\\\'"]/', $path) === 1) {
            return 'shell_syntax';
        }
        $real = @realpath($path);
        if ($real === false) {
            return 'not_found';
        }
        if (!is_file($real)) {
            return 'not_a_file';
        }
        if (!is_executable($real)) {
            return 'not_executable';
        }
        $name = strtolower(basename($real));
        if (str_contains($name, '-fpm') || str_contains($name, '-cgi')) {
            return 'not_a_cli_binary';
        }

        return null;
    }

    private function parseVersion(string $output): ?string
    {
        if (preg_match('/^PHP\s+(\d+\.\d+\.\d+)/mi', $output, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function typo3Minimum(): string
    {
        $major = (int) explode('.', ltrim($this->environment->typo3Version(), 'v'))[0];

        // TYPO3 v14 requires PHP 8.3+, v13 requires 8.2+.
        return $major >= 14 ? '8.3.0' : '8.2.0';
    }

    /**
     * @return list<string>
     */
    private function candidatePaths(): array
    {
        $v = \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION;
        $paths = [
            $this->runtimeConfiguration->current()->phpBinary,
            \PHP_BINARY,
            (string) (new PhpExecutableFinder())->find(),
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/usr/bin/php' . $v,
            '/usr/bin/php' . \PHP_MAJOR_VERSION,
        ];
        // Plesk + CloudLinux alt-php + cPanel EA-PHP, discovered generically (no
        // single server-specific path is hard-coded).
        foreach (['/opt/plesk/php/*/bin/php', '/opt/alt/php*/usr/bin/php', '/opt/cpanel/ea-php*/root/usr/bin/php'] as $pattern) {
            foreach (glob($pattern) ?: [] as $match) {
                $paths[] = $match;
            }
        }
        // PATH entries.
        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $dir = trim($dir);
            if ($dir !== '') {
                $paths[] = rtrim($dir, '/') . '/php';
            }
        }

        return array_values(array_filter(array_unique($paths), static fn (string $p): bool => $p !== ''));
    }
}
