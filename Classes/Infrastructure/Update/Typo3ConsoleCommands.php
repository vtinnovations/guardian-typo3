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

use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Process\CommandRequest;

/**
 * Builds the TYPO3 console commands used after a Composer update: database
 * extension setup and cache flushing. This is the compatibility adapter that
 * isolates any 13.4 vs 14 difference behind a version-neutral API. TYPO3 core
 * provides `extension:setup`; `database:updateschema` is optional and must not
 * be assumed to exist.
 * but should a future version rename a command, this is the single place to
 * adapt it.
 *
 * Schema changes are restricted to the SAFE, additive set `*.add,*.change`
 * (create tables/fields and widen types). Destructive operations (`*.drop`,
 * `*.change.table`) are deliberately NOT applied automatically — dropping data
 * during an unattended update is never safe. All commands run through the
 * configured PHP CLI and the project's own `vendor/bin/typo3`.
 */
final class Typo3ConsoleCommands
{
    /** Additive, non-destructive schema change types only. */
    private const SAFE_SCHEMA_CHANGES = '*.add,*.change';

    public function __construct(
        private readonly ComposerEnvironment $composerEnvironment,
    ) {
    }

    /**
     * `typo3 extension:setup --no-interaction`
     *
     * @throws GuardianException when the PHP CLI or the console binary is missing
     */
    public function schemaUpdate(): CommandRequest
    {
        return CommandRequest::create(
            array_merge($this->prefix(), ['extension:setup', '--no-interaction']),
            null,
            1800,
        );
    }

    /**
     * `typo3 cache:flush`
     *
     * @throws GuardianException
     */
    public function cacheFlush(): CommandRequest
    {
        return CommandRequest::create(
            array_merge($this->prefix(), ['cache:flush']),
            null,
            300,
        );
    }

    /**
     * `typo3 --version` — used by the runtime configuration test.
     *
     * @throws GuardianException
     */
    public function version(): CommandRequest
    {
        return CommandRequest::create(array_merge($this->prefix(), ['--version']), null, 30);
    }

    /**
     * @return list<string> [php, vendor/bin/typo3]
     */
    private function prefix(): array
    {
        $php = $this->composerEnvironment->phpBinary();
        if ($php === null) {
            throw new GuardianException('No PHP CLI binary is configured for TYPO3 console commands.');
        }
        $console = $this->composerEnvironment->typo3Console();
        if ($console === null) {
            throw new GuardianException('The TYPO3 console binary (vendor/bin/typo3) was not found.');
        }

        return [$php, $console];
    }
}
