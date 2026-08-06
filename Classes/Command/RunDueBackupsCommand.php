<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vtinnovations\GuardianTypo3\Application\Backup\ScheduledBackupRunner;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Runs whichever scheduled backup profiles are currently due.
 *
 * Intended to be invoked once a minute by a real cron / the TYPO3 Scheduler:
 *
 *   * / 5 * * * *  /usr/bin/php <project>/vendor/bin/typo3 guardian:backup:run-due
 *
 * The heavy lifting (lock, dump, archive, retention, notification) lives in the
 * application layer; this command is a thin CLI entry point.
 */
final class RunDueBackupsCommand extends Command
{
    public function __construct(
        private readonly ScheduledBackupRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Runs any scheduled Guardian backups (mini/full) that are currently due.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // A console run is held to the same entitlement as the backend: the
        // command reports the refusal instead of failing somewhere deeper.
        try {
            $due = $this->runner->runDue();
        } catch (GuardianException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $exit = Command::SUCCESS;
        foreach ($due as $type => $result) {
            $line = sprintf('[%s] %s: %s', $type, $result['status'], $result['message']);
            $output->writeln($line);
            if ($result['status'] === 'error') {
                $exit = Command::FAILURE;
            }
        }

        return $exit;
    }
}
