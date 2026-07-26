<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Application\Ter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\Typo3ExtensionStateInterface;
use Vtinnovations\GuardianTypo3\Application\Ter\TerExtensionMapper;
use Vtinnovations\GuardianTypo3\Application\Ter\TerSearchService;
use Vtinnovations\GuardianTypo3\Application\Update\PackageManager;
use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Filesystem\PathNormalizer;
use Vtinnovations\GuardianTypo3\Infrastructure\Composer\PathRepositoryInspector;
use Vtinnovations\GuardianTypo3\Infrastructure\Packagist\PackagistClient;
use Vtinnovations\GuardianTypo3\Infrastructure\Ter\TerClient;
use Vtinnovations\GuardianTypo3\Infrastructure\Ter\TerHttpTransportInterface;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\UpdateJobStore;
use Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery\FakeWorkingDirectory;

/**
 * Traces the whole TER search chain (client → parser → mapper) with a fake
 * transport — never the network. Covers the reported "content_blocks not found"
 * bug and the requirement that transport failures are NOT shown as "not found".
 */
final class TerSearchServiceTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/guardian-tersearch-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/project/vendor/composer', 0o755, true);
        file_put_contents($this->base . '/project/composer.json', json_encode(['require' => []]));
        file_put_contents($this->base . '/project/vendor/composer/installed.json', json_encode(['packages' => []]));
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->base));
    }

    /**
     * @param callable(string):array{status:int,body:string} $handler
     */
    private function service(callable $handler): TerSearchService
    {
        $transport = new class($handler) implements TerHttpTransportInterface {
            /** @var callable */
            private $handler;
            public function __construct(callable $handler) { $this->handler = $handler; }
            public function get(string $url): array { return ($this->handler)($url); }
        };
        $wd = new FakeWorkingDirectory($this->base . '/wd');
        $ter = new TerClient($transport, $wd);
        $packagist = new PackagistClient($transport, $wd);

        $project = $this->base . '/project';
        $env = new class($project) implements ProjectEnvironmentInterface {
            public function __construct(private readonly string $p) {}
            public function typo3Version(): string { return '13.4.9'; }
            public function projectPath(): string { return $this->p; }
            public function varPath(): string { return $this->p . '/var'; }
            public function publicPath(): string { return $this->p . '/public'; }
            public function isComposerMode(): bool { return true; }
            public function phpVersion(): string { return '8.2.0'; }
            public function loadedPhpExtensions(): array { return []; }
        };
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable('2026-07-26T00:00:00+00:00'); }
        };
        $extState = new class implements Typo3ExtensionStateInterface {
            public function isAvailable(): bool { return false; }
            public function isActive(string $extensionKey): bool { return true; }
            public function isProtected(string $extensionKey): bool { return false; }
            public function deactivate(string $extensionKey): void {}
            public function activate(string $extensionKey): void {}
        };
        $packages = new PackageManager($env, new PathRepositoryInspector(new PathNormalizer()), new UpdateJobStore($wd, $clock), $extState);

        return new TerSearchService($ter, $packagist, new TerExtensionMapper(), $packages, $env);
    }

    private function terContentBlocks(): string
    {
        return json_encode([
            'key' => 'content_blocks',
            'title' => 'Content Blocks',
            'description' => 'Content types for TYPO3.',
            'current_version' => [
                'number' => '1.3.0',
                'composer_name' => 'friendsoftypo3/content-blocks',
                'typo3_versions' => [12, 13],
                'php_version' => '>=8.1',
                'upload_date' => '2026-01-01',
            ],
        ]);
    }

    private function packagistSearch(array $names): string
    {
        return json_encode(['results' => array_map(static fn (string $n): array => ['name' => $n, 'description' => 'desc', 'repository' => '', 'url' => ''], $names)]);
    }

    private function packagistMeta(string $name): string
    {
        return $this->packagistMetaTypo3($name, '^12.4 || ^13.4');
    }

    private function packagistMetaTypo3(string $name, string $typo3Constraint): string
    {
        return json_encode(['packages' => [$name => [['version' => '1.3.0', 'require' => ['typo3/cms-core' => $typo3Constraint, 'php' => '>=8.1'], 'time' => '2026-01-01T00:00:00+00:00']]]]);
    }

    #[Test]
    public function exactExtensionKeySearchFindsContentBlocks(): void
    {
        $service = $this->service(function (string $url): array {
            if (str_contains($url, '/extension/content_blocks')) { return ['status' => 200, 'body' => $this->terContentBlocks()]; }
            if (str_contains($url, 'search.json')) { return ['status' => 200, 'body' => $this->packagistSearch(['friendsoftypo3/content-blocks'])]; }
            if (str_contains($url, '/p2/')) { return ['status' => 200, 'body' => $this->packagistMeta('friendsoftypo3/content-blocks')]; }

            return ['status' => 404, 'body' => ''];
        });

        $out = $service->search('content_blocks');
        self::assertGreaterThanOrEqual(1, $out['count']);
        $row = $out['results'][0];
        self::assertSame('content_blocks', $row['extension_key']);
        self::assertSame('friendsoftypo3/content-blocks', $row['composer_name']);
        self::assertTrue($row['typo3_compatible']);
        self::assertTrue($row['auto_installable']);
    }

    #[Test]
    public function hyphenatedQueryIsNormalisedToTheRealKey(): void
    {
        $service = $this->service(function (string $url): array {
            if (str_contains($url, '/extension/content_blocks')) { return ['status' => 200, 'body' => $this->terContentBlocks()]; }
            if (str_contains($url, 'search.json')) { return ['status' => 200, 'body' => $this->packagistSearch([])]; }

            return ['status' => 404, 'body' => ''];
        });

        $out = $service->search('content-blocks');
        self::assertSame('content_blocks', $out['results'][0]['extension_key']);
    }

    #[Test]
    public function keywordSearchResolvesComposerIdentityFromPackagist(): void
    {
        $service = $this->service(function (string $url): array {
            if (str_contains($url, '/extension/')) { return ['status' => 404, 'body' => '']; }
            if (str_contains($url, 'search.json')) { return ['status' => 200, 'body' => $this->packagistSearch(['georgringer/news'])]; }
            if (str_contains($url, '/p2/')) { return ['status' => 200, 'body' => $this->packagistMeta('georgringer/news')]; }

            return ['status' => 404, 'body' => ''];
        });

        $out = $service->search('news');
        self::assertSame(1, $out['count']);
        self::assertSame('news', $out['results'][0]['extension_key']);
        self::assertSame('georgringer/news', $out['results'][0]['composer_name']);
    }

    #[Test]
    public function oneIncompatibleResultDoesNotFailTheWholeSearchAndKeepsEachIdentity(): void
    {
        $service = $this->service(function (string $url): array {
            if (str_contains($url, '/extension/')) { return ['status' => 404, 'body' => '']; }
            if (str_contains($url, 'search.json')) { return ['status' => 200, 'body' => $this->packagistSearch(['friendsoftypo3/content-blocks', 'ichhabrecht/content-defender'])]; }
            if (str_contains($url, '/p2/friendsoftypo3/content-blocks')) { return ['status' => 200, 'body' => $this->packagistMetaTypo3('friendsoftypo3/content-blocks', '^12.4 || ^13.4')]; }
            if (str_contains($url, '/p2/ichhabrecht/content-defender')) { return ['status' => 200, 'body' => $this->packagistMetaTypo3('ichhabrecht/content-defender', '^11.5 || ^12.4')]; }

            return ['status' => 404, 'body' => ''];
        });

        $out = $service->search('content');
        $byKey = [];
        foreach ($out['results'] as $r) { $byKey[$r['extension_key']] = $r; }

        // content_blocks: identity + compatible → installable.
        self::assertSame('friendsoftypo3/content-blocks', $byKey['content_blocks']['composer_name']);
        self::assertTrue($byKey['content_blocks']['auto_installable']);

        // content_defender: identity RETAINED, but TYPO3-incompatible → not a
        // Composer-identity error, and it does not fail the whole search.
        self::assertSame('ichhabrecht/content-defender', $byKey['content_defender']['composer_name']);
        self::assertTrue($byKey['content_defender']['composer_available']);
        self::assertSame('composer_identity_available', $byKey['content_defender']['composer_state']);
        self::assertFalse($byKey['content_defender']['auto_installable']);
        self::assertSame('typo3_incompatible', $byKey['content_defender']['reason']);
        self::assertFalse($out['degraded']); // no request-level failure
    }

    #[Test]
    public function transportFailureIsNotReportedAsNotFound(): void
    {
        $service = $this->service(function (string $url): array {
            throw new GuardianException('dns_failure');
        });

        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('dns_failure');
        $service->search('content_blocks');
    }

    #[Test]
    public function malformedResponsesAreClassifiedAsUnsupportedSchema(): void
    {
        $service = $this->service(fn (string $url): array => ['status' => 200, 'body' => '<html>not json</html>']);

        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('unsupported_schema');
        $service->search('content_blocks');
    }

    #[Test]
    public function genuinelyNoMatchIsAnEmptySuccessNotAnError(): void
    {
        $service = $this->service(function (string $url): array {
            if (str_contains($url, '/extension/')) { return ['status' => 404, 'body' => '']; }
            if (str_contains($url, 'search.json')) { return ['status' => 200, 'body' => $this->packagistSearch([])]; }

            return ['status' => 404, 'body' => ''];
        });

        $out = $service->search('zzz_nothing_here');
        self::assertSame(0, $out['count']);
        self::assertSame([], $out['results']);
    }
}
