<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Recovery\Standalone;

use Vtinnovations\GuardianTypo3\Application\Contract\SystemLoggerInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;

/**
 * A SystemLogger for the standalone panel: TYPO3's logging framework is not
 * available, so events are appended to a bounded file inside Guardian's working
 * directory. It never records secrets (callers already redact them).
 */
final class StandaloneFileLogger implements SystemLoggerInterface
{
    private const FILE = 'recovery-panel/panel.log';

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    public function info(string $message, string $context = ''): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, string $context = ''): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, string $context = ''): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, string $context): void
    {
        $file = $this->workingDirectory->resolve(self::FILE);
        $dir = \dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o750, true);
        }
        $line = gmdate('c') . ' [' . $level . ']' . ($context !== '' ? ' {' . $context . '}' : '') . ' ' . $message;
        @file_put_contents($file, $line . "\n", \FILE_APPEND | \LOCK_EX);
        @chmod($file, 0o640);
    }
}
