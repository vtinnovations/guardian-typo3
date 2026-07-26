<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Environment;

/**
 * Immutable, read-only snapshot of the environment properties Guardian cares
 * about when deciding whether backup/update operations are even feasible.
 *
 * This is a pure data holder. It is produced by an application-layer inspector
 * from a {@see \Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface}
 * — it never reads the environment itself. In Phase 1 it reports only facts that
 * can be gathered WITHOUT invoking any external process (per the phase's
 * no-exec rule): the running PHP version, loaded extensions, Composer mode, and
 * whether Guardian's working directory is writable.
 */
final class EnvironmentCapabilities
{
    /**
     * @param list<string> $loadedExtensions
     */
    public function __construct(
        public readonly string $phpVersion,
        public readonly string $typo3Version,
        public readonly array $loadedExtensions,
        public readonly bool $composerMode,
        public readonly bool $workingDirectoryWritable,
        public readonly string $workingDirectory,
        public readonly bool $workingDirectoryExists,
    ) {
    }

    public function hasExtension(string $name): bool
    {
        return \in_array(strtolower($name), array_map('strtolower', $this->loadedExtensions), true);
    }
}
