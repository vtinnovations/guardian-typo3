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
use Vtinnovations\GuardianTypo3\Infrastructure\License\StoreIntegritySentinel;

/**
 * Developer/release tool: prints the transformed source representation to PIN the
 * first-level integrity digest for a FROZEN license file.
 *
 * It reads the exact bytes of the given license file, computes the digest, and
 * emits ready-to-paste method bodies for the integrity sentinel. It only PRINTS —
 * it never writes source, never touches production state, and the running
 * application never regenerates the embedded value itself. Use this only when a
 * deployment freezes its license file (offline/static licensing); the default
 * online model leaves the sentinel unpinned.
 */
final class LicenseDigestCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription('Prints the embedded integrity representation for a frozen Guardian license file (prints only).');
        $this->addArgument('file', InputArgument::REQUIRED, 'Absolute path to the frozen license.json to pin.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('file');
        if (!is_file($file) || !is_readable($file)) {
            $output->writeln('<error>License file not found or unreadable: ' . $file . '</error>');

            return Command::FAILURE;
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            $output->writeln('<error>Could not read the license file.</error>');

            return Command::FAILURE;
        }

        $md5 = hash('md5', $raw);
        $blob = StoreIntegritySentinel::encode($md5);

        // Split into three fragments and store them reversed; order() reverses back.
        $len = (int) ceil(\strlen($blob) / 3);
        $logical = str_split($blob, max(1, $len));
        $logical = array_pad($logical, 3, '');
        $storage = array_reverse($logical);

        $output->writeln('# Frozen license digest pinned. Paste the two method bodies below into');
        $output->writeln('# Classes/Infrastructure/License/StoreIntegritySentinel.php');
        $output->writeln('# (this changes source; it is never applied automatically).');
        $output->writeln('');
        $output->writeln('    private function pieces(): array');
        $output->writeln('    {');
        $output->writeln('        return [');
        foreach ($storage as $part) {
            $output->writeln("            '" . addslashes($part) . "',");
        }
        $output->writeln('        ];');
        $output->writeln('    }');
        $output->writeln('');
        $output->writeln('    private function order(array $parts): array');
        $output->writeln('    {');
        $output->writeln('        return array_reverse($parts);');
        $output->writeln('    }');

        return Command::SUCCESS;
    }
}
