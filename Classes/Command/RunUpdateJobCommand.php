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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vtinnovations\GuardianTypo3\Application\Update\UpdateJobRunner;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\UpdateJobStore;

/**
 * CLI worker that runs a single queued update job to completion. Spawned
 * detached by {@see \Vtinnovations\GuardianTypo3\Infrastructure\Update\UpdateWorkerSpawner};
 * it boots the full TYPO3 CLI (so schema/cache commands and the environment are
 * available), loads the job by ID and hands it to {@see UpdateJobRunner}.
 *
 * The command is not meant to be run by hand during normal operation, but doing
 * so is safe: it only acts on an already-persisted queued job.
 */
final class RunUpdateJobCommand extends Command
{
    public function __construct(
        private readonly UpdateJobStore $store,
        private readonly UpdateJobRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Runs a queued Guardian update job (internal worker).');
        $this->addArgument('jobId', InputArgument::REQUIRED, 'The update job ID to run.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobId = (string) $input->getArgument('jobId');
        $job = $this->store->current();
        if ($job === null || $job->id !== $jobId) {
            $output->writeln('<error>No matching queued job found for ' . $jobId . '.</error>');

            return Command::FAILURE;
        }
        if ($job->isFinished()) {
            $output->writeln('Job ' . $jobId . ' is already finished.');

            return Command::SUCCESS;
        }

        try {
            $this->runner->run($job);
        } catch (GuardianException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $final = $this->store->getArchived($jobId);
        $status = $final?->status->value ?? 'unknown';
        $output->writeln('Job ' . $jobId . ' finished with status: ' . $status);

        return $status === 'succeeded' ? Command::SUCCESS : Command::FAILURE;
    }
}
