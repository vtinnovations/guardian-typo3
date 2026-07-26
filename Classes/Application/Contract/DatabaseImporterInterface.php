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
 * Port for importing a SQL dump into the active TYPO3 database, server-side.
 *
 * Credentials are derived server-side, never exposed to the browser and never
 * logged; when an external mysql client is used the password is passed through a
 * restricted temporary defaults file. The dump is streamed, not loaded into
 * memory. This is the destructive counterpart to {@see DatabaseDumperInterface}
 * and is only ever reached from a confirmed, Pro-gated recovery.
 */
interface DatabaseImporterInterface
{
    /**
     * @param callable(string):void $log receives non-sensitive progress lines
     * @throws \Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException on any failure
     */
    public function importFrom(string $sqlFile, callable $log): void;

    /**
     * Whether the active database driver is supported for import (read-only).
     */
    public function isSupported(): bool;

    /**
     * Attempts a read-only connection, returning true on success.
     */
    public function canConnect(): bool;

    /**
     * Active database driver identifier (e.g. "mysqli", "pdo_mysql").
     */
    public function driver(): string;
}
