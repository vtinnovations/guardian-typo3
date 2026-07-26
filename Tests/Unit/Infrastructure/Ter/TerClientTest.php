<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Ter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Ter\TerClient;
use Vtinnovations\GuardianTypo3\Infrastructure\Ter\TerHttpTransportInterface;
use Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery\FakeWorkingDirectory;

final class TerClientTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/guardian-ter-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->base));
    }

    /**
     * @param callable(string):array{status:int,body:string} $handler
     */
    private function client(callable $handler): TerClient
    {
        $transport = new class($handler) implements TerHttpTransportInterface {
            /** @var callable */
            private $handler;
            public function __construct(callable $handler) { $this->handler = $handler; }
            public function get(string $url): array { return ($this->handler)($url); }
        };

        return new TerClient($transport, new FakeWorkingDirectory($this->base));
    }

    #[Test]
    public function exactKeyLookupHitsTheRealExtensionEndpoint(): void
    {
        $seen = [];
        $client = $this->client(function (string $url) use (&$seen): array {
            $seen[] = $url;

            return ['status' => 200, 'body' => json_encode(['key' => 'content_blocks', 'current_version' => ['number' => '1.3.0']])];
        });

        $result = $client->extensionOrNull('content_blocks');
        self::assertIsArray($result);
        self::assertSame('content_blocks', $result['key']);
        self::assertSame('https://extensions.typo3.org/api/v1/extension/content_blocks', $seen[0]);
    }

    #[Test]
    public function notFoundKeyReturnsNullNotAnError(): void
    {
        $client = $this->client(fn (string $url): array => ['status' => 404, 'body' => '']);
        self::assertNull($client->extensionOrNull('does_not_exist'));
    }

    #[Test]
    public function serverErrorBecomesAPreciseHttpCode(): void
    {
        $client = $this->client(fn (string $url): array => ['status' => 500, 'body' => 'oops']);
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('ter_http_error');
        $client->extensionOrNull('news');
    }

    #[Test]
    public function malformedBodyIsAnUnsupportedSchemaNotNotFound(): void
    {
        $client = $this->client(fn (string $url): array => ['status' => 200, 'body' => '<html>not json</html>']);
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('unsupported_schema');
        $client->extensionOrNull('news');
    }

    #[Test]
    public function transportFailureIsNotConvertedToNotFound(): void
    {
        $client = $this->client(function (string $url): array {
            throw new GuardianException('dns_failure');
        });
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('dns_failure');
        $client->extensionOrNull('news');
    }

    #[Test]
    public function invalidKeyIsRejectedBeforeAnyRequest(): void
    {
        $client = $this->client(function (string $url): array {
            throw new \RuntimeException('should not be called');
        });
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('ter_invalid_extension_key');
        $client->extensionOrNull('Bad Key!');
    }

    #[Test]
    public function unwrapsAWrappedExtensionPayload(): void
    {
        $client = $this->client(fn (string $url): array => ['status' => 200, 'body' => json_encode(['extension' => ['key' => 'news']])]);
        $result = $client->extensionOrNull('news');
        self::assertSame('news', $result['key']);
    }
}
