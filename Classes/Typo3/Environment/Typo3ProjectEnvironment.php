<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Typo3\Environment;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;

/**
 * TYPO3 adapter for {@see ProjectEnvironmentInterface}.
 *
 * This is the ONLY place Guardian reads TYPO3's Environment. It maps TYPO3's
 * canonical paths and mode onto Guardian's framework-neutral port so the domain
 * and application layers never touch \TYPO3\CMS\Core\Core\Environment directly.
 */
final class Typo3ProjectEnvironment implements ProjectEnvironmentInterface
{
    public function typo3Version(): string
    {
        return (new Typo3Version())->getVersion();
    }

    public function projectPath(): string
    {
        return Environment::getProjectPath();
    }

    public function varPath(): string
    {
        return Environment::getVarPath();
    }

    public function publicPath(): string
    {
        return Environment::getPublicPath();
    }

    public function isComposerMode(): bool
    {
        return Environment::isComposerMode();
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
