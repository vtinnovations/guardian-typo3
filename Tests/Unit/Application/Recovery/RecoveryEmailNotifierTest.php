<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Application\Recovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Configuration\RuntimeConfigurationService;
use Vtinnovations\GuardianTypo3\Application\Contract\MailerInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\RuntimeConfigurationRepositoryInterface;
use Vtinnovations\GuardianTypo3\Application\Recovery\RecoveryEmailNotifier;
use Vtinnovations\GuardianTypo3\Domain\Configuration\RuntimeConfiguration;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\PanelTokenStore;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\RecoveryPanelConfigStore;

final class RecoveryEmailNotifierTest extends TestCase
{
    /** @var list<array{to: string, subject: string, body: string, from: ?string}> */
    public array $sent = [];
    private bool $configured = true;
    private bool $throwOnSend = false;

    public function recordSend(string $to, string $subject, string $body, ?string $from): void
    {
        if ($this->throwOnSend) {
            throw new GuardianException('mail transport down');
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'from' => $from];
    }

    private function notifier(RuntimeConfiguration $config): RecoveryEmailNotifier
    {
        $mailer = new class($this) implements MailerInterface {
            public function __construct(private RecoveryEmailNotifierTest $probe) {}
            public function send(string $to, string $subject, string $body, ?string $from = null): void
            {
                $this->probe->recordSend($to, $subject, $body, $from);
            }
            public function isConfigured(): bool { return $this->probe->isConfigured(); }
        };
        $repo = new class($config) implements RuntimeConfigurationRepositoryInterface {
            public function __construct(private RuntimeConfiguration $config) {}
            public function load(): RuntimeConfiguration { return $this->config; }
            public function save(RuntimeConfiguration $c): RuntimeConfiguration { $this->config = $c; return $c; }
        };
        $panel = (new \ReflectionClass(RecoveryPanelConfigStore::class))->newInstanceWithoutConstructor();
        $tokens = (new \ReflectionClass(PanelTokenStore::class))->newInstanceWithoutConstructor();

        return new RecoveryEmailNotifier($mailer, new RuntimeConfigurationService($repo), $panel, $tokens);
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    #[Test]
    public function testSendDeliversToEveryConfiguredRecipient(): void
    {
        $config = RuntimeConfiguration::createDefault()->withNotifications(true, 'a@example.com, b@example.com', 'from@example.com');
        $recipient = $this->notifier($config)->sendTest();

        self::assertCount(2, $this->sent);
        self::assertSame('a@example.com, b@example.com', $recipient);
    }

    #[Test]
    public function testSendRequiresARecipient(): void
    {
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('no_recipient');
        $this->notifier(RuntimeConfiguration::createDefault())->sendTest();
    }

    #[Test]
    public function testSendRequiresAMailTransport(): void
    {
        $this->configured = false;
        $config = RuntimeConfiguration::createDefault()->withNotifications(true, 'a@example.com', '');
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('mail_transport_unavailable');
        $this->notifier($config)->sendTest();
    }

    #[Test]
    public function disabledNotificationsSendNothing(): void
    {
        $config = RuntimeConfiguration::createDefault()->withNotifications(false, 'a@example.com', '');
        $this->notifier($config)->sendEvent('recovery_started', ['job_id' => 'j1', 'backup_id' => 'b1']);
        self::assertSame([], $this->sent);
    }

    #[Test]
    public function eventEmailExcludesSecretsFromTheBody(): void
    {
        $config = RuntimeConfiguration::createDefault()->withNotifications(true, 'a@example.com', '');
        $this->notifier($config)->sendEvent('recovery_failed', [
            'job_id' => 'j1',
            'backup_id' => 'b1',
            'reason' => 'connection failed token=SECRET-abc123 password=hunter2',
        ]);

        self::assertCount(1, $this->sent);
        $body = $this->sent[0]['body'];
        self::assertStringContainsString('j1', $body);
        self::assertStringContainsString('b1', $body);
        self::assertStringNotContainsString('SECRET-abc123', $body);
        self::assertStringNotContainsString('hunter2', $body);
    }

    #[Test]
    public function aMailFailureIsSwallowedAndNeverThrows(): void
    {
        $this->throwOnSend = true;
        $config = RuntimeConfiguration::createDefault()->withNotifications(true, 'a@example.com', '');
        $logged = [];

        // Must not throw — a recovery must never be blocked by a mail failure.
        $this->notifier($config)->sendEvent('recovery_completed', ['job_id' => 'j1'], static function (string $line) use (&$logged): void { $logged[] = $line; });

        self::assertSame([], $this->sent);
        self::assertNotEmpty($logged);
    }
}
