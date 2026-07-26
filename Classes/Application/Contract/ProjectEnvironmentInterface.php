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
 * Port describing the host project's directory layout and mode.
 *
 * The Contao code read %kernel.project_dir% and assumed a Symfony/Contao tree.
 * TYPO3's layout is exposed through \TYPO3\CMS\Core\Core\Environment (project
 * path, var path, public path, config path, Composer mode). This interface lets
 * Guardian's domain reason about "where is var/, is this Composer mode" without
 * binding to either framework's specifics.
 */
interface ProjectEnvironmentInterface
{
    /** Running TYPO3 version reported by the core version API. */
    public function typo3Version(): string;

    /**
     * Absolute path to the project root (the directory containing composer.json
     * in Composer mode).
     */
    public function projectPath(): string;

    /**
     * Absolute path to the writable var/ directory (persistent runtime data).
     */
    public function varPath(): string;

    /**
     * Absolute path to the public web root.
     */
    public function publicPath(): string;

    /**
     * Whether the TYPO3 installation is running in Composer mode. Guardian's
     * update features are only meaningful in Composer mode.
     */
    public function isComposerMode(): bool;

    /**
     * Running PHP version (e.g. "8.4.3").
     */
    public function phpVersion(): string;

    /**
     * Lower-cased names of the extensions loaded in the current (web) PHP SAPI.
     *
     * @return list<string>
     */
    public function loadedPhpExtensions(): array;
}
