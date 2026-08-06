<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Support;

use Vtinnovations\GuardianTypo3\Application\Contract\SystemLoggerInterface;

final class RecordingLogger implements SystemLoggerInterface
{
    /** @var list<array{level: string, message: string, origin: string}> */
    public array $records = [];

    public function info(string $message, string $context = ''): void
    {
        $this->records[] = ['level' => 'info', 'message' => $message, 'origin' => $context];
    }

    public function warning(string $message, string $context = ''): void
    {
        $this->records[] = ['level' => 'warning', 'message' => $message, 'origin' => $context];
    }

    public function error(string $message, string $context = ''): void
    {
        $this->records[] = ['level' => 'error', 'message' => $message, 'origin' => $context];
    }

    /** Everything ever logged, concatenated, for absence assertions. */
    public function transcript(): string
    {
        return implode("\n", array_map(
            static fn (array $record): string => $record['level'] . ' ' . $record['origin'] . ' ' . $record['message'],
            $this->records
        ));
    }
}
