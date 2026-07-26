<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Backup;

/**
 * Immutable metadata about a completed database dump. Contains no credentials.
 */
final class DatabaseDumpResult
{
    public function __construct(
        public readonly int $bytes,
        public readonly string $method,
        public readonly string $serverVersion = '',
    ) {
    }
}
