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

use Vtinnovations\GuardianTypo3\Application\Contract\MailerInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Mailer stub for the standalone panel: TYPO3's mail transport is not booted, so
 * the panel reports mail as unconfigured. The pre-recovery notifier checks
 * {@see MailerInterface::isConfigured()} and skips sending, so no e-mail is ever
 * attempted from the standalone context.
 */
final class NullMailer implements MailerInterface
{
    public function send(string $to, string $subject, string $body, ?string $from = null): void
    {
        throw new GuardianException('Mail is not available from the standalone recovery panel.');
    }

    public function isConfigured(): bool
    {
        return false;
    }
}
