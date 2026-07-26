<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Update;

use Vtinnovations\GuardianTypo3\Application\Configuration\RuntimeConfigurationService;
use Vtinnovations\GuardianTypo3\Application\Contract\MailerInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;

/**
 * Sends update-lifecycle notifications to the configured recovery e-mail address
 * using the existing Guardian mailer. Best-effort: a mail failure never affects
 * the update itself. The body carries only non-sensitive facts — hostname,
 * administrator, mode, packages, versions, snapshot ID, result, rollback result
 * and a concise failure reason. It NEVER includes Composer logs, credentials,
 * database passwords or auth tokens.
 */
final class UpdateNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly RuntimeConfigurationService $runtimeConfiguration,
        private readonly ProjectEnvironmentInterface $environment,
    ) {
    }

    /**
     * @param array<string, scalar|null|list<string>> $context
     */
    public function notify(string $event, array $context): void
    {
        $config = $this->runtimeConfiguration->current();
        if (!$config->notificationsActive() || !$this->mailer->isConfigured()) {
            return;
        }

        $subjects = [
            'started' => 'Update started',
            'dry_run_completed' => 'Dry run completed',
            'succeeded' => 'Update succeeded',
            'failed' => 'Update FAILED',
            'rollback_started' => 'Rollback started',
            'rollback_succeeded' => 'Rollback succeeded',
            'rollback_failed' => 'Rollback FAILED',
        ];
        $subject = '[Guardian] ' . ($subjects[$event] ?? $event);

        $lines = [
            'Guardian for TYPO3 update notification: ' . ($subjects[$event] ?? $event),
            '',
            'Host:                 ' . $this->hostname(),
            'When:                 ' . gmdate('c'),
        ];
        foreach ([
            'admin' => 'Administrator',
            'mode' => 'Update mode',
            'job_id' => 'Job ID',
            'previous_typo3' => 'TYPO3 before',
            'result_typo3' => 'TYPO3 after',
            'safety_backup' => 'Safety backup',
            'result' => 'Result',
            'rollback_result' => 'Rollback result',
            'reason' => 'Reason',
        ] as $key => $label) {
            if (isset($context[$key]) && $context[$key] !== '' && !\is_array($context[$key])) {
                $lines[] = str_pad($label . ':', 22) . (string) $context[$key];
            }
        }
        if (isset($context['packages']) && \is_array($context['packages']) && $context['packages'] !== []) {
            $lines[] = 'Packages:             ' . implode(', ', array_slice($context['packages'], 0, 25));
        }
        if (isset($context['changes']) && \is_array($context['changes']) && $context['changes'] !== []) {
            $lines[] = '';
            $lines[] = 'Package changes:';
            foreach (array_slice($context['changes'], 0, 40) as $change) {
                $lines[] = '  - ' . (string) $change;
            }
        }

        try {
            foreach ($config->recoveryRecipients() as $recipient) {
                $this->mailer->send($recipient, $subject, implode("\n", $lines), $config->notificationSenderEmail);
            }
        } catch (\Throwable) {
            // Best-effort only.
        }
    }

    private function hostname(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');

        return \is_string($host) && $host !== '' ? $host : (gethostname() ?: 'unknown');
    }
}
