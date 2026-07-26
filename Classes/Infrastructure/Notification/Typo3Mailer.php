<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Notification;

use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Mail\MailerInterface as Typo3MailerInterface;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Vtinnovations\GuardianTypo3\Application\Contract\MailerInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\SystemLoggerInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * TYPO3 adapter for {@see MailerInterface}.
 *
 * The message is a normal {@see MailMessage} (a Symfony Mime e-mail carrying the
 * resolved sender, recipient, subject and text body); it is sent through TYPO3's
 * injected {@see \TYPO3\CMS\Core\Mail\MailerInterface}, which uses the configured
 * mail transport. The message's own send method was removed in modern TYPO3 and
 * is never called here — the injected mailer service is the single, TYPO3-13.4/14
 * compatible send path for both the "test" e-mail and every real recovery/update
 * notification.
 *
 * On failure the underlying (possibly transport-detailed) exception is logged
 * through Guardian's logger and re-thrown as a language-neutral code, so callers
 * never receive an SMTP DSN, credentials, transport details or a stack trace.
 */
final class Typo3Mailer implements MailerInterface
{
    public function __construct(
        private readonly Typo3MailerInterface $mailer,
        private readonly SystemLoggerInterface $logger,
    ) {
    }

    public function send(string $to, string $subject, string $body, ?string $from = null): void
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, \FILTER_VALIDATE_EMAIL)) {
            throw new GuardianException('invalid_recipient');
        }

        $message = GeneralUtility::makeInstance(MailMessage::class);
        $message
            ->from($this->resolveSender($from, $to))
            ->to(new Address($to))
            ->subject($subject)
            ->text($body);

        try {
            $this->mailer->send($message);
        } catch (\Throwable $e) {
            // Log the real reason safely; never surface it to the caller.
            $this->logger->error('Guardian mail send failed: ' . $e->getMessage(), 'notification');

            throw new GuardianException('mail_send_failed');
        }
    }

    public function isConfigured(): bool
    {
        $transport = (string) ($GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] ?? '');

        return $transport !== '' && $transport !== 'null';
    }

    private function resolveSender(?string $from, string $recipient): Address
    {
        $from = trim((string) $from);
        if ($from !== '' && filter_var($from, \FILTER_VALIDATE_EMAIL)) {
            return new Address($from);
        }

        $default = trim((string) ($GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] ?? ''));
        $name = trim((string) ($GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromName'] ?? 'Guardian'));
        if ($default !== '' && filter_var($default, \FILTER_VALIDATE_EMAIL)) {
            return new Address($default, $name !== '' ? $name : 'Guardian');
        }

        return new Address($recipient);
    }
}
