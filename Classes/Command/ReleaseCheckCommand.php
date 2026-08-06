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
use Vtinnovations\GuardianTypo3\Infrastructure\Version\ReleaseKeyring;

/**
 * The gate that stands between this working copy and a distributable build.
 *
 * A package whose verification keys are missing, malformed or placeholder-only
 * cannot verify anything the vendor sends it: every activation, refresh and push
 * would fail closed, and the installation would look broken for a reason nobody
 * could diagnose from the outside. That is a packaging mistake, so it is caught
 * at packaging time.
 *
 * The command inspects what the build actually contains — not a configuration
 * file, not an environment variable — and exits non-zero with the reason when
 * the build is not fit to ship. Wire it into the release pipeline before the
 * artefact is assembled.
 */
final class ReleaseCheckCommand extends Command
{
    public function __construct(private readonly ReleaseKeyring $keyring)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Verifies this build is fit to distribute (checks the pinned verification keys).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $problems = $this->keyring->productionReadiness();

        if ($problems === []) {
            $output->writeln(sprintf(
                '<info>Release check passed.</info> Pinned verification keys: %s.',
                implode(', ', $this->keyring->keyIds())
            ));

            return Command::SUCCESS;
        }

        $output->writeln('<error>Release check failed. This build must not be distributed.</error>');
        foreach ($problems as $problem) {
            $output->writeln('  - ' . $problem);
        }
        $output->writeln('');
        $output->writeln('Pin the approved vendor verification key(s) in');
        $output->writeln('Classes/Infrastructure/Version/ReleaseKeyring.php, then run this check again.');

        return Command::FAILURE;
    }
}
