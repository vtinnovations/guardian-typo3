<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Recovery;

use Vtinnovations\GuardianTypo3\Application\Contract\RecoveryHistoryStoreInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;

/**
 * JSON-backed recovery history at var/guardian/recovery_history.json, capped to
 * the 50 most recent entries. Never stores credentials or absolute paths.
 */
final class JsonRecoveryHistoryStore implements RecoveryHistoryStoreInterface
{
    private const FILE = 'recovery_history.json';
    private const CAP = 50;

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    public function record(array $record): void
    {
        $entries = $this->list();
        array_unshift($entries, $record);
        $entries = \array_slice($entries, 0, self::CAP);

        $file = $this->workingDirectory->resolve(self::FILE);
        $dir = \dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o750, true);
        }
        $json = json_encode($entries, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($json !== false && @file_put_contents($file, $json, \LOCK_EX) !== false) {
            @chmod($file, 0o640);
        }
    }

    public function list(): array
    {
        $file = $this->workingDirectory->resolve(self::FILE);
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($file), true);

        return \is_array($decoded) ? array_values($decoded) : [];
    }
}
