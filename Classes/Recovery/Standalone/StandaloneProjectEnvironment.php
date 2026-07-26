<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Recovery\Standalone;

use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;

/**
 * ProjectEnvironment for the STANDALONE recovery panel. It cannot read
 * \TYPO3\CMS\Core\Core\Environment (TYPO3 is not booted), so it derives the
 * project layout purely from the panel's own location on disk: the panel lives
 * in the public web root, whose parent is the project root, and var/ sits under
 * the project root. All paths are absolute and canonicalised by the caller.
 */
final class StandaloneProjectEnvironment implements ProjectEnvironmentInterface
{
    public function __construct(
        private readonly string $projectPath,
        private readonly string $publicPath,
    ) {
    }

    public function typo3Version(): string
    {
        // Best-effort: read the installed cms-core version without booting TYPO3.
        $composer = $this->projectPath . '/vendor/typo3/cms-core/composer.json';
        if (is_file($composer)) {
            $data = json_decode((string) @file_get_contents($composer), true);
            if (\is_array($data) && isset($data['version']) && \is_string($data['version'])) {
                return $data['version'];
            }
        }

        return '';
    }

    public function projectPath(): string
    {
        return $this->projectPath;
    }

    public function varPath(): string
    {
        return $this->projectPath . '/var';
    }

    public function publicPath(): string
    {
        return $this->publicPath;
    }

    public function isComposerMode(): bool
    {
        return is_file($this->projectPath . '/composer.json');
    }

    public function phpVersion(): string
    {
        return \PHP_VERSION;
    }

    public function loadedPhpExtensions(): array
    {
        return array_values(array_map('strtolower', get_loaded_extensions()));
    }
}
