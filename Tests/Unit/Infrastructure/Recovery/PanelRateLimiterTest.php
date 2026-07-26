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
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\PanelRateLimiter;

final class PanelRateLimiterTest extends TestCase
{
    private PanelRateLimiter $limiter;
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/guardian-rl-' . bin2hex(random_bytes(6));
        $this->limiter = new PanelRateLimiter(new FakeWorkingDirectory($this->base));
    }

    #[Test]
    public function locksOutAfterTooManyFailures(): void
    {
        $ip = '203.0.113.7';
        self::assertFalse($this->limiter->check($ip)['locked']);
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->registerFailure($ip);
        }
        $result = $this->limiter->check($ip);
        self::assertTrue($result['locked']);
        self::assertGreaterThan(0, $result['retryAfter']);
    }

    #[Test]
    public function successResetsTheCounter(): void
    {
        $ip = '203.0.113.8';
        $this->limiter->registerFailure($ip);
        $this->limiter->registerFailure($ip);
        $this->limiter->registerSuccess($ip);
        self::assertFalse($this->limiter->check($ip)['locked']);
    }

    #[Test]
    public function storesOnlyHashedIpsNeverTheRawAddress(): void
    {
        $ip = '198.51.100.42';
        $this->limiter->registerFailure($ip);
        $raw = (string) file_get_contents($this->base . '/recovery-panel/rate-limit.json');
        self::assertStringNotContainsString($ip, $raw);
    }
}
