<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Domain\License;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\License\DomainNormalizer;

final class DomainNormalizerTest extends TestCase
{
    private DomainNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new DomainNormalizer();
    }

    #[Test]
    #[DataProvider('hosts')]
    public function normalizesUntrustedHostValues(string $input, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->normalize($input));
    }

    /** @return array<string, array{string, string}> */
    public static function hosts(): array
    {
        return [
            'plain' => ['example.com', 'example.com'],
            'uppercase + spaces' => ['  Example.COM ', 'example.com'],
            'scheme + userinfo + port + path' => ['https://user:pass@Example.com:8443/foo?bar=1', 'example.com'],
            'trailing dot' => ['example.com.', 'example.com'],
            'subdomain' => ['Shop.Example.com', 'shop.example.com'],
            'empty' => ['', ''],
            'invalid chars' => ['bad host!', ''],
            'header injection attempt' => ["example.com\r\nX: y", ''],
        ];
    }

    #[Test]
    public function allowedMatchingCoversExactAndSubdomains(): void
    {
        self::assertTrue($this->normalizer->matchesAllowed('example.com', 'example.com'));
        self::assertTrue($this->normalizer->matchesAllowed('shop.example.com', 'example.com'));
        self::assertTrue($this->normalizer->matchesAllowed('a.b.example.com', 'example.com'));
        self::assertFalse($this->normalizer->matchesAllowed('evil-example.com', 'example.com'));
        self::assertFalse($this->normalizer->matchesAllowed('example.com', 'other.com'));
        self::assertFalse($this->normalizer->matchesAllowed('', 'example.com'));
    }
}
