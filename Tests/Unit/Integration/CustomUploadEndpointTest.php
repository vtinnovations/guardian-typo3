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
 * Guarantees the custom-extension upload no longer hides its real failure behind
 * the generic "The upload could not be processed." message, and that the whole
 * request path (field name, route, controller, safe error) stays consistent.
 */
final class CustomUploadEndpointTest extends TestCase
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
    public function theGenericSwallowingFallbackIsGone(): void
    {
        $controller = $this->read('Classes/Controller/Backend/GuardianAjaxController.php');
        // The generic string must no longer be RETURNED as an error to the browser.
        self::assertStringNotContainsString("'error' => 'The upload could not be processed.'", $controller);
        // uploadExtension's catch now BINDS the throwable (no longer discards it).
        self::assertMatchesRegularExpression('/function uploadExtension\\(.*?catch \\(\\\\Throwable \\$e\\)/s', $controller);
    }

    #[Test]
    public function controllerLogsAtTheFirstLineAndBeforeGuards(): void
    {
        $controller = $this->read('Classes/Controller/Backend/GuardianAjaxController.php');
        // Protected proof-of-reach logging happens before the guard clauses.
        self::assertMatchesRegularExpression('/function uploadExtension\\(.*?systemLogger->info\\(.*?guardPost\\(\\$request\\)/s', $controller);
    }

    #[Test]
    public function unexpectedFailuresAreLoggedAndReturnedAsAStructuredSafeResult(): void
    {
        $controller = $this->read('Classes/Controller/Backend/GuardianAjaxController.php');
        // The Throwable is CAPTURED (not discarded), logged with class + message…
        self::assertMatchesRegularExpression('/catch \\(\\\\Throwable \\$e\\) \\{.*?systemLogger->error\\(/s', $controller);
        // …and a structured, safe failure body (errorCode + reason + area) is returned.
        self::assertStringContainsString('private function uploadFailureBody(string $errorCode)', $controller);
        self::assertStringContainsString("'errorCode' => \$errorCode", $controller);
        self::assertStringContainsString("uploadFailureBody('upload_processing_error')", $controller);
        self::assertStringContainsString('shortClassName($e)', $controller);
    }

    #[Test]
    public function stagesInsideTheProjectRuntimeNotTheSystemTempDir(): void
    {
        $controller = $this->read('Classes/Controller/Backend/GuardianAjaxController.php');
        // The upload is staged via the PSR-7 file directly — no sys_get_temp_dir.
        self::assertStringContainsString('acceptUploadedFile($uploaded)', $controller);
        self::assertMatchesRegularExpression('/function uploadExtension\\(.*?acceptUploadedFile/s', $controller);
        self::assertStringNotContainsString('sys_get_temp_dir()', $controller);

        $staging = $this->read('Classes/Infrastructure/Upload/UploadStagingArea.php');
        self::assertStringContainsString("private const ROOT = 'extensions/uploads'", $staging);
        // Restrictive permissions, never 0777.
        self::assertStringNotContainsString('0o777', $staging);
        self::assertStringNotContainsString('0777', $staging);
        // Cryptographically random 16-byte id + bounded chunk stream-copy fallback.
        self::assertStringContainsString('random_bytes(16)', $staging);
        self::assertStringContainsString('function streamCopy', $staging);
    }

    #[Test]
    public function javascriptAppendsTheExactFieldPhpReadsAndSetsNoContentType(): void
    {
        $js = $this->read('Resources/Public/JavaScript/guardian.js');
        // JS field name…
        self::assertStringContainsString("fd.append('extensionArchive', file.files[0])", $js);
        // …matches the PSR-7 field the controller reads first.
        $controller = $this->read('Classes/Controller/Backend/GuardianAjaxController.php');
        self::assertStringContainsString("\$files['extensionArchive']", $controller);
        // The multipart Content-Type must never be set manually (the browser adds
        // the boundary itself).
        self::assertStringNotContainsString("'Content-Type': 'multipart", $js);
        // The upload error path renders a structured failure (summary + safe
        // details + recommendation), not a bare message.
        self::assertStringContainsString('function uploadFailureHtml', $js);
        self::assertStringContainsString("xtxt('upload.rec.' + code", $js);
        self::assertStringContainsString('d.area', $js);
    }

    #[Test]
    public function uploadRouteAndModuleEndpointAreRegistered(): void
    {
        self::assertStringContainsString("'guardian_upload_extension'", $this->read('Configuration/Backend/AjaxRoutes.php'));
        self::assertStringContainsString("'uploadExtension' => 'guardian_upload_extension'", $this->read('Classes/Controller/Backend/GuardianModuleController.php'));
    }

    #[Test]
    public function languageOverlaysExposeTheNewSafeUploadReasons(): void
    {
        $en = $this->read('Resources/Private/Language/locallang.xlf');
        $de = $this->read('Resources/Private/Language/de.locallang.xlf');
        foreach (['upload_move_failed', 'upload_processing_error', 'no_file_field'] as $code) {
            self::assertStringContainsString('js.pkg.reason.' . $code, $en);
            self::assertStringContainsString('js.pkg.reason.' . $code, $de);
        }
        // Parity is preserved.
        self::assertSame($this->ids($en), $this->ids($de));
    }

    /**
     * @return list<string>
     */
    private function ids(string $xml): array
    {
        preg_match_all('/trans-unit id="([^"]+)"/', $xml, $m);
        $ids = $m[1];
        sort($ids);

        return $ids;
    }
}
