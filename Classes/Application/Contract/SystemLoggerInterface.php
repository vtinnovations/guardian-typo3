<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Contract;

/**
 * High-level audit/system logging port.
 *
 * The Contao original wrote to tl_log via a Monolog channel. The TYPO3 adapter
 * writes to TYPO3's logging framework (which can surface in the backend log
 * module and sys_log). Guardian code depends only on this small surface, so the
 * audit trail is guaranteed and secrets can be redacted centrally in the
 * adapter.
 */
interface SystemLoggerInterface
{
    public function info(string $message, string $context = ''): void;

    public function warning(string $message, string $context = ''): void;

    public function error(string $message, string $context = ''): void;
}
