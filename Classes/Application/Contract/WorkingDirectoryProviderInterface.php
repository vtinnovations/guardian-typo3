<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Contract;

/**
 * Provides the absolute path to Guardian's private working directory (all
 * runtime state: config, license cache, schedule, job logs, backups). The
 * implementation resolves and contains this path safely under the project's
 * var/ directory; callers never construct the path themselves.
 */
interface WorkingDirectoryProviderInterface
{
    /** Whether the Guardian directory itself already exists. */
    public function exists(): bool;

    /**
     * Absolute path to Guardian's working directory (e.g. <var>/guardian).
     */
    public function path(): string;

    /**
     * Absolute path to a named file or subdirectory INSIDE the working
     * directory. Implementations must reject names that would escape it.
     */
    public function resolve(string $relativePath): string;

    /**
     * Whether the working directory exists and is writable (or can be created).
     * A read-only check — it does not create anything.
     */
    public function isWritable(): bool;
}
