<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Notification;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Contract\MailerInterface;
use Vtinnovations\GuardianTypo3\Infrastructure\Notification\Typo3Mailer;

/**
 * Structural guarantees that the Guardian mailer sends through TYPO3's injected
 * mailer service (compatible with TYPO3 13.4 and 14) and never calls the removed
 * MailMessage::send(). These assertions hold without a TYPO3 bootstrap: the type
 * names are read via reflection (which does not autoload them) and the send path
 * is verified from source.
 */
final class Typo3MailerTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 4) . '/Classes/Infrastructure/Notification/Typo3Mailer.php');
    }

    #[Test]
    public function constructorInjectsTheTypo3MailerInterfaceAndGuardianLogger(): void
    {
        $ctor = (new \ReflectionClass(Typo3Mailer::class))->getConstructor();
        self::assertNotNull($ctor);
        self::assertTrue($ctor->isPublic(), 'constructor must be public so the DI container can build it');

        $params = $ctor->getParameters();
        self::assertCount(2, $params);

        // First dependency is TYPO3's own mailer service (autowired by the container).
        $mailerType = $params[0]->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $mailerType);
        self::assertSame('TYPO3\\CMS\\Core\\Mail\\MailerInterface', $mailerType->getName());

        // Second dependency is Guardian's logger port for safe error logging.
        $loggerType = $params[1]->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $loggerType);
        self::assertSame('Vtinnovations\\GuardianTypo3\\Application\\Contract\\SystemLoggerInterface', $loggerType->getName());
    }

    #[Test]
    public function sendsThroughTheInjectedMailerServiceNotMailMessageSend(): void
    {
        $source = $this->source();
        // Uses the injected TYPO3 mailer.
        self::assertStringContainsString('$this->mailer->send($message)', $source);
        // The removed MailMessage::send() is never called.
        self::assertStringNotContainsString('$message->send()', $source);
        self::assertStringNotContainsString('MailMessage::send', $source);
        // No version checks around the send path.
        self::assertStringNotContainsString('version_compare', $source);
    }

    #[Test]
    public function failuresAreLoggedAndReThrownAsASafeCodeWithoutTransportDetail(): void
    {
        $source = $this->source();
        // The underlying exception is logged through Guardian's logger…
        self::assertStringContainsString('$this->logger->error(', $source);
        // …and re-thrown as a language-neutral code (no DSN/credentials/trace).
        self::assertStringContainsString("throw new GuardianException('mail_send_failed')", $source);
        // The old behaviour that concatenated the raw exception message is gone.
        self::assertStringNotContainsString("Sending the e-mail failed: ' . \$e->getMessage()", $source);
    }

    #[Test]
    public function isStillTheGuardianMailerPortImplementation(): void
    {
        self::assertTrue((new \ReflectionClass(Typo3Mailer::class))->implementsInterface(MailerInterface::class));
    }

    #[Test]
    public function noGuardianClassStillCallsTheRemovedMailMessageSend(): void
    {
        $root = \dirname(__DIR__, 4) . '/Classes';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $offenders = [];
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            // A MailMessage instance's ->send()/::send() must never be called.
            if (preg_match('/\$(message|mailMessage|mail)\s*->\s*send\s*\(\s*\)/', $code) === 1
                || str_contains($code, 'MailMessage::send')) {
                $offenders[] = $file->getPathname();
            }
        }
        self::assertSame([], $offenders, 'MailMessage::send() must not be called anywhere in Guardian');
    }
}
