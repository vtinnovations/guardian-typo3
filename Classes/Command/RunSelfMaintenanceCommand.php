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
use Vtinnovations\GuardianTypo3\Application\SelfMaintenance\SelfMaintenanceService;

/**
 * Detached CLI worker that performs the deferred Guardian self-disable. Spawned
 * by {@see \Vtinnovations\GuardianTypo3\Infrastructure\SelfMaintenance\SelfMaintenanceSpawner}
 * once the backend response has completed. It only acts on the single persisted
 * Guardian self-maintenance job and is hard-bound to the Guardian identity by the
 * service; it can never disable or remove another package.
 */
final class RunSelfMaintenanceCommand extends Command
{
    public function __construct(
        private readonly SelfMaintenanceService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Runs the deferred Guardian self-maintenance job (internal worker).');
        $this->addArgument('jobId', InputArgument::REQUIRED, 'The self-maintenance job ID to run.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->service->runDisable((string) $input->getArgument('jobId'));
        $status = $this->service->status();
        $final = \is_array($status) ? (string) ($status['status'] ?? 'unknown') : 'unknown';
        $output->writeln('Guardian self-maintenance finished with status: ' . $final);

        return $final === 'succeeded' ? Command::SUCCESS : Command::FAILURE;
    }
}
