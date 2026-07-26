<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Domain\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\Configuration\RuntimeConfiguration;

final class RuntimeConfigurationTest extends TestCase
{
    #[Test]
    public function defaultConfigurationUsesSafePanelFilename(): void
    {
        $config = RuntimeConfiguration::createDefault();

        self::assertSame('', $config->phpBinary);
        self::assertSame(RuntimeConfiguration::DEFAULT_PANEL_FILENAME, $config->recoveryPanelFilename);
        self::assertFalse($config->hasPhpBinary());
    }

    #[Test]
    public function invalidEmailIsDroppedRatherThanStored(): void
    {
        $config = RuntimeConfiguration::fromArray([
            'recovery_email' => 'not-an-email',
            'notification_sender_email' => 'ops@example.com',
        ]);

        self::assertSame('', $config->recoveryEmail);
        self::assertSame('ops@example.com', $config->notificationSenderEmail);
    }

    #[Test]
    public function panelFilenameWithTraversalFallsBackToDefault(): void
    {
        $config = RuntimeConfiguration::fromArray([
            'recovery_panel_filename' => '../evil.php',
        ]);

        self::assertSame(RuntimeConfiguration::DEFAULT_PANEL_FILENAME, $config->recoveryPanelFilename);
    }

    #[Test]
    public function panelFilenameMustEndInPhp(): void
    {
        $config = RuntimeConfiguration::fromArray([
            'recovery_panel_filename' => 'recovery.txt',
        ]);

        self::assertSame(RuntimeConfiguration::DEFAULT_PANEL_FILENAME, $config->recoveryPanelFilename);
    }

    #[Test]
    public function validCustomPanelFilenameIsKept(): void
    {
        $config = RuntimeConfiguration::fromArray([
            'recovery_panel_filename' => 'x9-secret_panel.php',
        ]);

        self::assertSame('x9-secret_panel.php', $config->recoveryPanelFilename);
    }

    #[Test]
    public function toArrayRoundTripsThroughFromArray(): void
    {
        $config = RuntimeConfiguration::fromArray([
            'php_binary' => '/opt/php/bin/php',
            'composer_phar' => '/usr/local/bin/composer.phar',
            'recovery_email' => 'admin@example.com',
            'notification_sender_email' => 'noreply@example.com',
            'recovery_panel_filename' => 'panel.php',
        ]);

        $restored = RuntimeConfiguration::fromArray($config->toArray());

        self::assertEquals($config, $restored);
    }

    #[Test]
    public function withPhpBinaryProducesTrimmedImmutableCopy(): void
    {
        $config = RuntimeConfiguration::createDefault();
        $updated = $config->withPhpBinary('  /usr/bin/php  ');

        self::assertSame('', $config->phpBinary, 'original must be unchanged');
        self::assertSame('/usr/bin/php', $updated->phpBinary);
        self::assertTrue($updated->hasPhpBinary());
    }

    #[Test]
    public function notificationsAreDisabledByDefault(): void
    {
        $config = RuntimeConfiguration::createDefault();
        self::assertFalse($config->recoveryNotificationsEnabled);
        self::assertSame([], $config->recoveryRecipients());
        self::assertFalse($config->notificationsActive());
    }

    #[Test]
    public function normalisesMultipleRecipientsAndDropsInvalidOnes(): void
    {
        $config = RuntimeConfiguration::createDefault()
            ->withNotifications(true, 'ops@example.com, not-an-email; admin@example.com spam@', 'from@example.com');

        self::assertSame('ops@example.com, admin@example.com', $config->recoveryEmail);
        self::assertSame(['ops@example.com', 'admin@example.com'], $config->recoveryRecipients());
        self::assertSame('from@example.com', $config->notificationSenderEmail);
        self::assertTrue($config->notificationsActive());
    }

    #[Test]
    public function deduplicatesRecipientsCaseInsensitively(): void
    {
        $config = RuntimeConfiguration::createDefault()->withNotifications(true, 'A@Example.com, a@example.com', '');
        self::assertCount(1, $config->recoveryRecipients());
    }

    #[Test]
    public function notificationsAreInactiveWhenDisabledEvenWithRecipients(): void
    {
        $config = RuntimeConfiguration::createDefault()->withNotifications(false, 'ops@example.com', '');
        self::assertTrue($config->hasRecoveryEmail());
        self::assertFalse($config->notificationsActive());
    }

    #[Test]
    public function notificationSettingsRoundTripThroughArray(): void
    {
        $config = RuntimeConfiguration::createDefault()->withNotifications(true, 'ops@example.com', 'from@example.com');
        $restored = RuntimeConfiguration::fromArray($config->toArray());

        self::assertTrue($restored->recoveryNotificationsEnabled);
        self::assertSame('ops@example.com', $restored->recoveryEmail);
        self::assertTrue($restored->notificationsActive());
    }
}
