<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\PanelTokenStore;

final class PanelTokenStoreTest extends TestCase
{
    private string $base;
    private PanelTokenStore $store;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/guardian-token-' . bin2hex(random_bytes(6));
        $this->store = new PanelTokenStore(new FakeWorkingDirectory($this->base));
    }

    protected function tearDown(): void
    {
        @unlink($this->base . '/recovery-panel/token.json');
    }

    #[Test]
    public function generatesAHighEntropyUrlSafeToken(): void
    {
        $token = $this->store->generate();
        self::assertGreaterThanOrEqual(43, \strlen($token));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
    }

    #[Test]
    public function storesOnlyTheHashNeverThePlaintext(): void
    {
        $token = $this->store->generate();
        $raw = (string) file_get_contents($this->base . '/recovery-panel/token.json');
        self::assertStringNotContainsString($token, $raw);
        self::assertStringContainsString('"hash"', $raw);
    }

    #[Test]
    public function verifiesTheCorrectTokenAndRejectsWrongOnes(): void
    {
        $token = $this->store->generate();
        self::assertTrue($this->store->verify($token));
        self::assertFalse($this->store->verify($token . 'x'));
        self::assertFalse($this->store->verify(''));
        self::assertFalse($this->store->verify(null));
    }

    #[Test]
    public function rotationInvalidatesThePreviousToken(): void
    {
        $first = $this->store->generate();
        $second = $this->store->rotate();
        self::assertNotSame($first, $second);
        self::assertFalse($this->store->verify($first));
        self::assertTrue($this->store->verify($second));
    }

    #[Test]
    public function statusExposesOnlyAMaskedPreview(): void
    {
        $token = $this->store->generate();
        $status = $this->store->status();
        self::assertTrue($status['exists']);
        self::assertSame('file', $status['source']);
        self::assertStringNotContainsString($token, $status['preview']);
        self::assertStringContainsString('…', $status['preview']);
    }
}
