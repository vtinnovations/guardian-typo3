<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Typo3\Logging;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Vtinnovations\GuardianTypo3\Application\Contract\SystemLoggerInterface;

/**
 * TYPO3 adapter for {@see SystemLoggerInterface}.
 *
 * Writes Guardian's high-level audit events through TYPO3's logging framework
 * (a PSR-3 logger injected by the DI container via LoggerAwareInterface, which
 * TYPO3 wires automatically). Configuring where those records land — file,
 * sys_log, backend log module — is an installation concern; Guardian only emits.
 *
 * Secret redaction is centralised here so no credential or token can leak into
 * the audit trail regardless of what a caller passes.
 */
final class Typo3SystemLogger implements SystemLoggerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct()
    {
        // A NullLogger keeps the adapter usable before/without DI injection and
        // in unit tests, so callers never have to null-check.
        $this->logger = new NullLogger();
    }

    public function info(string $message, string $context = ''): void
    {
        $this->logger->info($this->redactSecrets($message), $this->context($context));
    }

    public function warning(string $message, string $context = ''): void
    {
        $this->logger->warning($this->redactSecrets($message), $this->context($context));
    }

    public function error(string $message, string $context = ''): void
    {
        $this->logger->error($this->redactSecrets($message), $this->context($context));
    }

    /**
     * @return array<string, string>
     */
    private function context(string $context): array
    {
        $data = ['component' => 'guardian_typo3'];
        if ($context !== '') {
            $data['origin'] = $context;
        }

        return $data;
    }

    /**
     * Strips obvious secrets before persisting. Ports the redaction rules from
     * the audited Contao JobLog so credentials, tokens and DSN passwords never
     * reach the audit trail.
     */
    private function redactSecrets(string $message): string
    {
        $patterns = [
            '/(--(?:password|pwd|pass))=\S+/i' => '$1=***',
            '/(--(?:password|pwd|pass))\s+\S+/i' => '$1 ***',
            '/((?:password|pwd|pass|secret|token|api_key|mysql_pwd))=\S+/i' => '$1=***',
            '/(Bearer\s+)\S+/i' => '$1***',
            '#([a-z][a-z0-9+.-]*://[^/@\s:]+):[^@\s]+@#i' => '$1:***@',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $message = preg_replace($pattern, $replacement, $message) ?? $message;
        }

        return $message;
    }
}
