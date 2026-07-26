<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Recovery;

use Vtinnovations\GuardianTypo3\Application\Configuration\RuntimeConfigurationService;
use Vtinnovations\GuardianTypo3\Application\Contract\MailerInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\PanelTokenStore;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\RecoveryPanelConfigStore;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\UpdateJobLog;

/**
 * Sends recovery-related e-mails to the configured recipient(s):
 *
 *   - a pre-recovery "rescue" mail with the standalone recovery-panel filename,
 *   - lifecycle notifications for the recovery/rollback events Guardian defines.
 *
 * Notifications are sent only when the operator ENABLED them and configured at
 * least one valid recipient. Every mail is best-effort: a delivery failure is
 * reported to the recovery log but never changes the recovery result or blocks a
 * rollback. Bodies carry ONLY safe operational facts (host, job id, backup id,
 * time, result, a concise redacted reason, rollback result) — never tokens,
 * passwords, licence keys, database credentials or full logs.
 */
final class RecoveryEmailNotifier
{
    private const EVENT_SUBJECTS = [
        'recovery_started' => 'Recovery started',
        'recovery_completed' => 'Recovery completed',
        'recovery_failed' => 'Recovery FAILED',
        'rollback_started' => 'Rollback started',
        'rollback_completed' => 'Rollback completed',
        'rollback_failed' => 'Rollback FAILED',
        'interrupted_recovery_detected' => 'Interrupted recovery detected',
    ];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly RuntimeConfigurationService $runtimeConfiguration,
        private readonly RecoveryPanelConfigStore $panelConfig,
        private readonly PanelTokenStore $tokenStore,
    ) {
    }

    /**
     * Best-effort: never aborts a recovery if mail fails.
     *
     * @param callable(string):void $logger
     */
    public function sendPreRecovery(string $backupId, callable $logger): void
    {
        $config = $this->runtimeConfiguration->current();
        if (!$config->notificationsActive()) {
            return;
        }
        try {
            $this->deliverPreRecovery($config->recoveryRecipients(), $config->notificationSenderEmail, $backupId, $this->panelConfig->filename());
            $logger('Pre-recovery e-mail sent to the configured address(es).');
        } catch (\Throwable $e) {
            $logger('Pre-recovery e-mail could not be sent: ' . $e->getMessage());
        }
    }

    /**
     * Notify a recovery/rollback lifecycle event. Best-effort and safe: a mail
     * failure is logged (when a logger is given) and swallowed.
     *
     * @param array<string, scalar|null> $context safe fields: job_id, backup_id, result, reason, rollback_result
     * @param ?callable(string):void $logger
     */
    public function sendEvent(string $event, array $context = [], ?callable $logger = null): void
    {
        $config = $this->runtimeConfiguration->current();
        if (!$config->notificationsActive() || !$this->mailer->isConfigured()) {
            return;
        }
        $subject = '[Guardian] ' . (self::EVENT_SUBJECTS[$event] ?? $event);
        $body = $this->eventBody($event, $context);
        try {
            foreach ($config->recoveryRecipients() as $recipient) {
                $this->mailer->send($recipient, $subject, $body, $config->notificationSenderEmail);
            }
        } catch (\Throwable $e) {
            if ($logger !== null) {
                $logger('Recovery notification e-mail could not be sent: ' . $e->getMessage());
            }
        }
    }

    /**
     * Explicit test send (reports its outcome). Allowed whenever a recipient is
     * configured, so operators can verify delivery before enabling notifications.
     *
     * @throws GuardianException machine reason code on failure
     */
    public function sendTest(): string
    {
        $config = $this->runtimeConfiguration->current();
        $recipients = $config->recoveryRecipients();
        if ($recipients === []) {
            throw new GuardianException('no_recipient');
        }
        if (!$this->mailer->isConfigured()) {
            throw new GuardianException('mail_transport_unavailable');
        }
        $body = $this->eventBody('recovery_started', ['job_id' => '(test)', 'backup_id' => '(test)', 'result' => 'This is a Guardian test notification.']);
        foreach ($recipients as $recipient) {
            $this->mailer->send($recipient, '[Guardian] Test notification', $body, $config->notificationSenderEmail);
        }

        return implode(', ', $recipients);
    }

    /**
     * @param list<string> $recipients
     */
    private function deliverPreRecovery(array $recipients, string $sender, string $backupId, string $panelFilename): void
    {
        $status = $this->tokenStore->status();
        $preview = $status['exists'] ? (string) $status['preview'] : '(no token generated yet)';
        $body = implode("\n", [
            'Guardian for TYPO3 is about to run a RECOVERY.',
            '',
            'Host:                 ' . $this->hostname(),
            'Backup ID:            ' . $backupId,
            'Recovery panel file:  /' . $panelFilename,
            'Access token (masked): ' . $preview,
            '',
            'If the backend becomes unreachable during recovery, open the recovery',
            'panel URL above and sign in with the recovery access token you saved',
            'when you generated it. For your security the full token is NEVER sent by',
            'e-mail — only the masked preview above, so you can confirm which token',
            'is current.',
        ]);
        foreach ($recipients as $recipient) {
            $this->mailer->send($recipient, '[Guardian] Recovery access (keep safe)', $body, $sender);
        }
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function eventBody(string $event, array $context): string
    {
        $lines = [
            'Guardian for TYPO3 recovery notification: ' . (self::EVENT_SUBJECTS[$event] ?? $event),
            '',
            'Host:                 ' . $this->hostname(),
            'When:                 ' . gmdate('c'),
        ];
        foreach ([
            'job_id' => 'Job ID',
            'backup_id' => 'Backup ID',
            'result' => 'Result',
            'rollback_result' => 'Rollback result',
            'reason' => 'Reason',
        ] as $key => $label) {
            $value = $context[$key] ?? null;
            if ($value === null || $value === '' || \is_array($value)) {
                continue;
            }
            $safe = mb_substr(UpdateJobLog::redactSecrets(trim((string) $value)), 0, 240);
            $lines[] = str_pad($label . ':', 22) . $safe;
        }
        $lines[] = '';
        $lines[] = 'This message contains operational status only — never tokens, passwords or logs.';

        return implode("\n", $lines);
    }

    private function hostname(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');

        return \is_string($host) && $host !== '' ? $host : (gethostname() ?: 'unknown');
    }
}
