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

use Symfony\Component\Process\PhpExecutableFinder;
use Vtinnovations\GuardianTypo3\Application\Configuration\RuntimeConfigurationService;
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;

/**
 * Resolves the PHP CLI binary and a real composer.phar to drive updates, and
 * computes the platform-ignore flags needed when the CLI PHP is missing an
 * extension the site's web PHP has. Ports the CMS-independent binary-discovery
 * logic from the audited Contao ComposerUpdateStep / PlatformChecker.
 *
 * A configured absolute path (backend "PHP CLI settings") always wins, exactly
 * like Contao Manager; autodetection is only a fallback. Only real .phar files
 * are accepted for Composer — never a shell wrapper that might pick its own PHP.
 */
final class ComposerEnvironment
{
    public function __construct(
        private readonly ProjectEnvironmentInterface $environment,
        private readonly RuntimeConfigurationService $runtimeConfiguration,
    ) {
    }

    public function phpBinary(): ?string
    {
        $configured = $this->runtimeConfiguration->current()->phpBinary;
        if ($configured !== '' && $this->isUsableCliBinary($configured)) {
            return $configured;
        }
        if (\defined('PHP_BINARY') && \PHP_BINARY !== '' && $this->isUsableCliBinary(\PHP_BINARY)) {
            return \PHP_BINARY;
        }
        $found = (new PhpExecutableFinder())->find();
        if ($found !== false && $found !== '' && $this->isUsableCliBinary($found)) {
            return $found;
        }
        foreach ($this->phpCandidates() as $candidate) {
            if ($this->isUsableCliBinary($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function composerBinary(): ?string
    {
        $configured = $this->runtimeConfiguration->current()->composerPhar;
        if ($configured !== '' && is_file($configured) && is_readable($configured)) {
            return $configured;
        }
        foreach ($this->composerCandidates() as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The TYPO3 console binary (vendor/bin/typo3) used for schema + cache steps.
     */
    public function typo3Console(): ?string
    {
        $path = rtrim($this->environment->projectPath(), '/') . '/vendor/bin/typo3';

        return is_file($path) ? $path : null;
    }

    /**
     * --ignore-platform-req flags for ext-* required by the project but missing
     * in the current runtime. Inside the worker the current runtime IS the CLI
     * PHP that will drive Composer, so get_loaded_extensions() is authoritative.
     *
     * @return list<string>
     */
    public function ignorePlatformFlags(): array
    {
        $required = $this->requiredExtensions();
        if ($required === []) {
            return [];
        }
        $loaded = array_map('strtolower', get_loaded_extensions());
        $flags = [];
        foreach ($required as $ext) {
            if (!\in_array($ext, $loaded, true)) {
                $flags[] = '--ignore-platform-req=ext-' . $ext;
            }
        }

        return $flags;
    }

    /**
     * @return list<string> ext names without the "ext-" prefix
     */
    private function requiredExtensions(): array
    {
        $project = rtrim($this->environment->projectPath(), '/');
        $extensions = [];
        foreach ([$project . '/composer.json'] as $file) {
            $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
            if (!\is_array($data)) {
                continue;
            }
            foreach (['require', 'require-dev'] as $section) {
                foreach (array_keys((array) ($data[$section] ?? [])) as $name) {
                    if (\is_string($name) && str_starts_with($name, 'ext-')) {
                        $extensions[] = strtolower(substr($name, 4));
                    }
                }
            }
        }
        $lock = $project . '/composer.lock';
        if (is_file($lock)) {
            $data = json_decode((string) @file_get_contents($lock), true);
            foreach (array_keys((array) ($data['platform'] ?? [])) as $name) {
                if (\is_string($name) && str_starts_with($name, 'ext-')) {
                    $extensions[] = strtolower(substr($name, 4));
                }
            }
        }

        return array_values(array_unique($extensions));
    }

    private function isUsableCliBinary(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        $name = basename($path);
        if (str_contains($name, '-fpm') || str_contains($name, '-cgi')) {
            return false;
        }

        return is_file($path) && !is_dir($path) && is_executable($path);
    }

    /**
     * @return list<string>
     */
    private function phpCandidates(): array
    {
        $v = \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION;

        return [
            '/opt/plesk/php/' . $v . '/bin/php',
            '/opt/cpanel/ea-php' . \PHP_MAJOR_VERSION . \PHP_MINOR_VERSION . '/root/usr/bin/php',
            '/usr/bin/php' . $v,
            '/usr/bin/php' . \PHP_MAJOR_VERSION,
            '/usr/local/bin/php',
            '/usr/bin/php',
        ];
    }

    /**
     * @return list<string>
     */
    private function composerCandidates(): array
    {
        return [
            rtrim($this->environment->projectPath(), '/') . '/composer.phar',
            '/opt/psa/var/modules/composer/composer.phar',
            '/usr/local/share/composer/composer.phar',
            '/usr/local/bin/composer.phar',
            '/usr/share/composer/composer.phar',
        ];
    }
}
