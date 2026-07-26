<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Environment;

use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Environment\EnvironmentCapabilities;

/**
 * Builds a read-only {@see EnvironmentCapabilities} snapshot from the project
 * environment port. Strictly non-invasive in Phase 1: it inspects only values
 * already available in-process (PHP version, loaded extensions, Composer mode,
 * working-directory writability) and never spawns a process.
 */
final class EnvironmentInspector
{
    public function __construct(
        private readonly ProjectEnvironmentInterface $environment,
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    public function inspect(): EnvironmentCapabilities
    {
        return new EnvironmentCapabilities(
            phpVersion: $this->environment->phpVersion(),
            typo3Version: $this->environment->typo3Version(),
            loadedExtensions: $this->environment->loadedPhpExtensions(),
            composerMode: $this->environment->isComposerMode(),
            workingDirectoryWritable: $this->workingDirectory->isWritable(),
            workingDirectory: $this->workingDirectory->path(),
            workingDirectoryExists: $this->workingDirectory->exists(),
        );
    }
}
