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
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Provides a private, project-local Composer runtime so Composer always has a
 * usable HOME/COMPOSER_HOME even on hosts (e.g. CloudLinux alt-php) whose
 * FPM/CLI process environment has none — the cause of Composer aborting with
 * "The HOME or COMPOSER_HOME environment variable must be set".
 *
 * The runtime lives under Guardian's existing writable working directory, OUTSIDE
 * the public web root:
 *
 *   var/guardian/runtime/home           → HOME
 *   var/guardian/runtime/composer-home  → COMPOSER_HOME (config/auth.json/vendor)
 *   var/guardian/runtime/composer-cache → COMPOSER_CACHE_DIR
 *
 * The directories are created with restrictive permissions (owned by the current
 * process user), and their absolute paths are exported as an explicit env overlay
 * that is MERGED with — never replaces — the inherited process environment, so
 * PATH, TLS/CA, proxy and any real Composer auth are preserved.
 */
final class ComposerRuntime
{
    private const HOME = 'runtime/home';
    private const COMPOSER_HOME = 'runtime/composer-home';
    private const COMPOSER_CACHE = 'runtime/composer-cache';

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    public function homeDir(): string
    {
        return $this->workingDirectory->resolve(self::HOME);
    }

    public function composerHomeDir(): string
    {
        return $this->workingDirectory->resolve(self::COMPOSER_HOME);
    }

    public function composerCacheDir(): string
    {
        return $this->workingDirectory->resolve(self::COMPOSER_CACHE);
    }

    /**
     * Creates the runtime directories if missing (0700), verifies they are
     * writable, and returns the explicit Composer environment overlay.
     *
     * @return array{HOME: string, COMPOSER_HOME: string, COMPOSER_CACHE_DIR: string, COMPOSER_NO_INTERACTION: string}
     * @throws GuardianException composer_runtime_directory_unavailable
     */
    public function ensure(): array
    {
        foreach ([$this->homeDir(), $this->composerHomeDir(), $this->composerCacheDir()] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
                throw new GuardianException('composer_runtime_directory_unavailable');
            }
            @chmod($dir, 0o700);
            if (!is_dir($dir) || !is_writable($dir)) {
                throw new GuardianException('composer_runtime_directory_unavailable');
            }
        }

        return $this->env();
    }

    /**
     * The explicit environment overlay (does not touch the filesystem).
     *
     * @return array{HOME: string, COMPOSER_HOME: string, COMPOSER_CACHE_DIR: string, COMPOSER_NO_INTERACTION: string}
     */
    public function env(): array
    {
        return [
            'HOME' => $this->homeDir(),
            'COMPOSER_HOME' => $this->composerHomeDir(),
            'COMPOSER_CACHE_DIR' => $this->composerCacheDir(),
            'COMPOSER_NO_INTERACTION' => '1',
        ];
    }

    /**
     * Validates everything a Composer run needs BEFORE it is started, with a
     * precise machine-readable error for each failure. Also ensures the runtime.
     *
     * @throws GuardianException composer_runtime_directory_unavailable|composer_php_binary_missing|composer_phar_unreadable|composer_manifest_missing
     */
    public function preflight(string $phpBinary, string $composerBinary, string $projectDir): void
    {
        $this->ensure();

        if ($phpBinary === '' || !is_file($phpBinary) || !is_executable($phpBinary)) {
            throw new GuardianException('composer_php_binary_missing');
        }
        if ($composerBinary === '' || !is_file($composerBinary) || !is_readable($composerBinary)) {
            throw new GuardianException('composer_phar_unreadable');
        }
        if (!is_file(rtrim($projectDir, '/') . '/composer.json')) {
            throw new GuardianException('composer_manifest_missing');
        }
    }

    /**
     * Protected, credential-free diagnostics for the licensing/update log.
     *
     * @return array{home_configured: bool, composer_home_configured: bool, cache_dir_configured: bool, runtime_writable: bool, exit_code: int, stderr_summary: string, composer_recommendation: ?string}
     */
    public function diagnostics(int $exitCode, string $stderr): array
    {
        return [
            'home_configured' => $this->homeDir() !== '',
            'composer_home_configured' => $this->composerHomeDir() !== '',
            'cache_dir_configured' => $this->composerCacheDir() !== '',
            'runtime_writable' => is_dir($this->homeDir()) && is_writable($this->homeDir())
                && is_dir($this->composerHomeDir()) && is_writable($this->composerHomeDir()),
            'exit_code' => $exitCode,
            'stderr_summary' => $this->redact($stderr),
            'composer_recommendation' => $this->developmentBuildRecommendation($stderr),
        ];
    }

    /**
     * The old-Composer "development build" notice is a RECOMMENDATION, not the
     * HOME failure — returned separately so callers never conflate the two.
     */
    public function developmentBuildRecommendation(string $stderr): ?string
    {
        $lower = strtolower($stderr);
        if (str_contains($lower, 'development build') || str_contains($lower, 'not a stable release of composer')) {
            return 'Composer is a development build — updating composer.phar to a stable release is recommended (not required).';
        }

        return null;
    }

    /**
     * Redacts credentials and trims to a bounded, non-sensitive summary. Never
     * returns auth tokens, passwords or full environment values.
     */
    public function redact(string $stderr): string
    {
        $s = $stderr;
        $s = preg_replace('/(--(?:password|pwd|pass|token))=\S+/i', '$1=***', $s) ?? $s;
        $s = preg_replace('/((?:password|passwd|secret|token|api[_-]?key|bearer|authorization)\S{0,20})[:=]\s*\S+/i', '$1: ***', $s) ?? $s;
        $s = preg_replace('#([a-z][a-z0-9+.-]*://[^/@\s:]+):[^@\s]+@#i', '$1:***@', $s) ?? $s;
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);

        return mb_substr($s, 0, 500);
    }
}
