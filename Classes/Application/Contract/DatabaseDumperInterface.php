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

use Vtinnovations\GuardianTypo3\Application\Backup\DatabaseDumpResult;

/**
 * Port for producing a database dump to a file, server-side.
 *
 * The connection configuration is derived server-side from TYPO3; credentials
 * are never exposed to the browser, never logged, and — when an external
 * mysqldump is used — passed through a restricted temporary defaults file rather
 * than the process argument list.
 */
interface DatabaseDumperInterface
{
    /**
     * Streams a dump of the active TYPO3 database into $targetFile.
     *
     * @param callable(string):void $log receives non-sensitive progress lines
     * @throws \Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException on any failure (never silently succeeds without a dump)
     */
    public function dumpTo(string $targetFile, callable $log): DatabaseDumpResult;
}
