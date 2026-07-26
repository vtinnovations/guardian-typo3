<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Structural guarantees that the two Settings sections are actually wired and no
 * longer disabled placeholders, and that the configured PHP CLI flows through a
 * single source of truth.
 */
final class SettingsSectionWiringTest extends TestCase
{
    private function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents($this->root() . '/' . $relative);
    }

    #[Test]
    public function allSettingsRoutesAreRegistered(): void
    {
        $routes = $this->read('Configuration/Backend/AjaxRoutes.php');
        foreach (['guardian_notifications_save', 'guardian_notifications_test', 'guardian_php_detect', 'guardian_php_test', 'guardian_php_save'] as $route) {
            self::assertStringContainsString("'" . $route . "'", $routes);
        }
        $module = $this->read('Classes/Controller/Backend/GuardianModuleController.php');
        self::assertStringContainsString("'notificationsSave' => 'guardian_notifications_save'", $module);
        self::assertStringContainsString("'phpDetect' => 'guardian_php_detect'", $module);
    }

    #[Test]
    public function stateChangingSettingsEndpointsAreAdminPostAndLicenceGated(): void
    {
        $controller = $this->read('Classes/Controller/Backend/GuardianAjaxController.php');
        // POST + admin on every write endpoint.
        foreach (['notificationsSave', 'notificationsTest', 'phpTest', 'phpSave'] as $method) {
            self::assertMatchesRegularExpression('/function ' . $method . '\\(.*?guardPost\\(\\$request\\)/s', $controller, $method . ' must be POST + admin');
        }
        // Sending a recovery test e-mail keeps the Pro licence rule.
        self::assertMatchesRegularExpression('/function notificationsTest\\(.*?requirePro\\(\\)/s', $controller);
    }

    #[Test]
    public function settingsControlsAreNoLongerDisabledPlaceholders(): void
    {
        $settings = $this->read('Resources/Private/Partials/Guardian/Settings.html');
        // The functional controls carry data-actions…
        foreach (['notifications-save', 'notifications-test', 'php-detect', 'php-test', 'php-save'] as $action) {
            self::assertStringContainsString('data-action="' . $action . '"', $settings);
        }
        // …and the recovery-email / PHP inputs are no longer disabled.
        self::assertStringContainsString('id="recoveryEmailInput"', $settings);
        self::assertStringContainsString('id="recoveryNotifyEnabled"', $settings);
        self::assertStringNotContainsString('updater-disabled-note', $settings);
    }

    #[Test]
    public function javascriptWiresBothSettingsSections(): void
    {
        $js = $this->read('Resources/Public/JavaScript/guardian.js');
        foreach (['function saveNotifications', 'function testNotifications', 'function phpDetect', 'function phpTest', 'function phpSave'] as $fn) {
            self::assertStringContainsString($fn, $js);
        }
        self::assertStringContainsString("'notifications-save': saveNotifications", $js);
        self::assertStringContainsString("'php-save': phpSave", $js);
        // Loads the persisted enabled flag so saved values remain after reload.
        self::assertStringContainsString('recovery_notifications_enabled', $js);
    }

    #[Test]
    public function theConfiguredPhpBinaryHasASingleSourceUsedEverywhere(): void
    {
        // ComposerEnvironment resolves the configured path first…
        $env = $this->read('Classes/Infrastructure/Update/ComposerEnvironment.php');
        self::assertStringContainsString('runtimeConfiguration->current()->phpBinary', $env);

        // …and the update + recovery + console services all go through it (no
        // second PHP-path configuration system).
        foreach ([
            'Classes/Application/Update/UpdateJobRunner.php',
            'Classes/Application/Recovery/RestoreService.php',
            'Classes/Application/Recovery/VendorRecoveryService.php',
            'Classes/Infrastructure/Update/Typo3ConsoleCommands.php',
        ] as $file) {
            self::assertStringContainsString('composerEnvironment->phpBinary()', $this->read($file), $file);
        }
    }

    #[Test]
    public function languageOverlaysKeepExactParity(): void
    {
        $en = $this->ids($this->read('Resources/Private/Language/locallang.xlf'));
        $de = $this->ids($this->read('Resources/Private/Language/de.locallang.xlf'));
        sort($en);
        sort($de);
        self::assertSame($en, $de);
        self::assertContains('settings.email.enable', $en);
        self::assertContains('js.settings.php.unsupported_version', $en);
    }

    /**
     * @return list<string>
     */
    private function ids(string $xml): array
    {
        preg_match_all('/trans-unit id="([^"]+)"/', $xml, $m);

        return $m[1];
    }
}
