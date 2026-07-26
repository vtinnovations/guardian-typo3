<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Update;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Filesystem\PathNormalizer;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\AnalysisWorkspace;
use Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery\FakeWorkingDirectory;

final class AnalysisWorkspaceTest extends TestCase
{
    private string $base;
    private string $project;
    private string $jobId = '20260728-120000-deadbeef';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/guardian-aw-' . bin2hex(random_bytes(6));
        $this->project = $this->base . '/project';
        mkdir($this->project, 0o755, true);
        file_put_contents($this->project . '/composer.json', json_encode([
            'require' => ['typo3/cms-core' => '^13.4'],
            'repositories' => [
                ['type' => 'path', 'url' => 'packages/my_ext'],
                ['type' => 'composer', 'url' => 'https://repo.packagist.org'],
            ],
        ], \JSON_PRETTY_PRINT));
        file_put_contents($this->project . '/composer.lock', '{"packages":[]}');
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->base));
    }

    private function workspace(): AnalysisWorkspace
    {
        $env = new class($this->project) implements ProjectEnvironmentInterface {
            public function __construct(private readonly string $p) {}
            public function typo3Version(): string { return '13.4.9'; }
            public function projectPath(): string { return $this->p; }
            public function varPath(): string { return $this->p . '/var'; }
            public function publicPath(): string { return $this->p . '/public'; }
            public function isComposerMode(): bool { return true; }
            public function phpVersion(): string { return \PHP_VERSION; }
            public function loadedPhpExtensions(): array { return []; }
        };

        return new AnalysisWorkspace(new FakeWorkingDirectory($this->base . '/wd'), $env, new PathNormalizer());
    }

    #[Test]
    public function createsAnIsolatedCopyWithoutTouchingTheLiveProject(): void
    {
        $liveJson = (string) file_get_contents($this->project . '/composer.json');

        $dir = $this->workspace()->create($this->jobId);

        self::assertDirectoryExists($dir);
        self::assertFileExists($dir . '/composer.json');
        self::assertFileExists($dir . '/composer.lock');
        // The workspace is under the private runtime dir, NOT the project.
        self::assertStringContainsString('/runtime/analysis/' . $this->jobId, $dir);
        // The live composer.json is byte-for-byte unchanged.
        self::assertSame($liveJson, (string) file_get_contents($this->project . '/composer.json'));
    }

    #[Test]
    public function rewritesRelativePathRepositoriesToAbsolute(): void
    {
        $dir = $this->workspace()->create($this->jobId);
        $data = json_decode((string) file_get_contents($dir . '/composer.json'), true);

        self::assertSame($this->project . '/packages/my_ext', $data['repositories'][0]['url']);
        // Non-path repositories are left untouched.
        self::assertSame('https://repo.packagist.org', $data['repositories'][1]['url']);
    }

    #[Test]
    public function cleanupRemovesTheWorkspace(): void
    {
        $dir = $this->workspace()->create($this->jobId);
        self::assertDirectoryExists($dir);
        $this->workspace()->cleanup($this->jobId);
        self::assertDirectoryDoesNotExist($dir);
    }

    #[Test]
    public function rejectsAnInvalidJobId(): void
    {
        $this->expectException(GuardianException::class);
        $this->workspace()->create('../escape');
    }
}
