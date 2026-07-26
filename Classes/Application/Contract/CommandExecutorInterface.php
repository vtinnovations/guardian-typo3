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

use Vtinnovations\GuardianTypo3\Domain\Process\CommandRequest;
use Vtinnovations\GuardianTypo3\Domain\Process\CommandResult;

/**
 * Port for running external processes (composer, mysqldump, tar, the CLI
 * worker). Implementations MUST execute the argv array without a shell.
 *
 * Phase 1 ships only an implementation that refuses to run and throws — no
 * process execution happens in this phase. The interface exists now so the
 * update/backup pipelines built later depend on this abstraction rather than on
 * Symfony Process or exec() directly.
 */
interface CommandExecutorInterface
{
    /**
     * Runs the command to completion and returns its result.
     *
     * @throws \Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException on spawn failure or when execution is unavailable.
     */
    public function run(CommandRequest $request): CommandResult;

    /**
     * Whether this executor is able to run commands in the current environment
     * (e.g. exec/proc_open available and not disabled). In Phase 1 this is
     * always false.
     */
    public function isAvailable(): bool;
}
