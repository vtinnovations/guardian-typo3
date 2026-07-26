<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Process;

use Vtinnovations\GuardianTypo3\Application\Contract\CommandExecutorInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\NotImplementedException;
use Vtinnovations\GuardianTypo3\Domain\Process\CommandRequest;
use Vtinnovations\GuardianTypo3\Domain\Process\CommandResult;

/**
 * The Phase 1 command executor: it does not run anything.
 *
 * Guardian deliberately ships NO process execution in this phase (no exec(),
 * no proc_open(), no Symfony Process). This implementation satisfies the
 * {@see CommandExecutorInterface} seam so the rest of the code can be wired and
 * reviewed, but any attempt to run a command fails loudly with a
 * {@see NotImplementedException} rather than silently pretending to succeed.
 *
 * A real, shell-free executor (argv arrays via Symfony Process) is introduced in
 * the update/backup phases, where it will be reviewed on its own.
 */
final class UnavailableCommandExecutor implements CommandExecutorInterface
{
    public function run(CommandRequest $request): CommandResult
    {
        throw NotImplementedException::forFeature(
            'external command execution (' . $request->binary() . ')'
        );
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
