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

use Vtinnovations\GuardianTypo3\Application\Contract\MailerInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\ScheduleConfigStoreInterface;
use Vtinnovations\GuardianTypo3\Domain\Backup\BackupType;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\ExtensionInformation;

/**
 * Sends backup success/failure e-mails and the "test e-mail", ported from the
 * audited Contao BackupNotifier. Recipient/sender/toggle settings come from the
 * schedule config; delivery goes through the {@see MailerInterface} adapter.
 * Notification failures never abort a backup (they are swallowed), except for
 * the explicit test e-mail, which reports its outcome.
 */
final class BackupNotificationService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly ScheduleConfigStoreInterface $configStore,
        private readonly ExtensionInformation $extensionInformation,
    ) {
    }

    public function notifySuccess(BackupType $type, array $manifest): void
    {
        $notifications = $this->notifications();
        if (empty($notifications['on_success']) || (string) $notifications['email'] === '') {
            return;
        }

        $subject = sprintf('[Guardian] %s backup succeeded: %s', ucfirst($type->value), (string) ($manifest['id'] ?? ''));
        $body = implode("\n", [
            'A ' . $type->value . ' backup completed successfully.',
            '',
            'Backup ID:   ' . (string) ($manifest['id'] ?? ''),
            'Type:        ' . (string) ($manifest['type'] ?? ''),
            'Size:        ' . (string) ($manifest['archive_size_human'] ?? ''),
            'Files:       ' . (string) ($manifest['file_count'] ?? 0),
            'TYPO3:       ' . (string) ($manifest['typo3_version'] ?? ''),
            'Host:        ' . (string) ($manifest['hostname'] ?? ''),
            'Checksum:    ' . (string) ($manifest['checksum'] ?? ''),
            '',
            'Sent by ' . $this->extensionInformation->productName() . ' ' . $this->extensionInformation->version() . '.',
        ]);

        $this->trySend((string) $notifications['email'], $subject, $body, (string) $notifications['sender_email']);
    }

    public function notifyFailure(BackupType $type, string $error): void
    {
        $notifications = $this->notifications();
        if (empty($notifications['on_failure']) || (string) $notifications['email'] === '') {
            return;
        }

        $subject = sprintf('[Guardian] %s backup FAILED', ucfirst($type->value));
        $body = implode("\n", [
            'A ' . $type->value . ' backup failed.',
            '',
            'Error: ' . $error,
            '',
            'Please check the Guardian backend and the server logs.',
        ]);

        $this->trySend((string) $notifications['email'], $subject, $body, (string) $notifications['sender_email']);
    }

    /**
     * Sends a test e-mail with the currently stored settings.
     *
     * @throws GuardianException when no recipient is configured or sending fails
     */
    public function sendTest(): string
    {
        $notifications = $this->notifications();
        $recipient = (string) ($notifications['email'] ?? '');
        if ($recipient === '') {
            throw new GuardianException('Configure and save a recipient e-mail address first.');
        }
        if (!$this->mailer->isConfigured()) {
            throw new GuardianException('No mail transport is configured in this TYPO3 installation.');
        }

        $this->mailer->send(
            $recipient,
            '[Guardian] Test notification',
            "This is a Guardian for TYPO3 test e-mail.\n\nIf you received this, backup notifications are working.",
            (string) ($notifications['sender_email'] ?? '')
        );

        return $recipient;
    }

    /**
     * @return array<string, mixed>
     */
    private function notifications(): array
    {
        $config = $this->configStore->loadConfig();

        return \is_array($config['notifications'] ?? null) ? $config['notifications'] : [];
    }

    private function trySend(string $to, string $subject, string $body, string $from): void
    {
        try {
            $this->mailer->send($to, $subject, $body, $from);
        } catch (\Throwable) {
            // A misconfigured mailer must never abort or fail a backup.
        }
    }
}
