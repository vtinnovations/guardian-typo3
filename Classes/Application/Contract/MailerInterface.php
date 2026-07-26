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
 * Minimal outbound e-mail port for backup and recovery notifications.
 *
 * The Contao original injected Symfony's MailerInterface directly. TYPO3 ships
 * its own \TYPO3\CMS\Core\Mail\Mailer (a Symfony Mailer under the hood); the
 * adapter (a later phase) will wrap it. Guardian's own tiny interface keeps
 * notification logic independent of the transport and easy to fake in tests.
 */
interface MailerInterface
{
    /**
     * @throws \Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException when the mail cannot be sent.
     */
    public function send(string $to, string $subject, string $body, ?string $from = null): void;

    /**
     * Whether a usable mail transport is configured in this installation.
     */
    public function isConfigured(): bool;
}
