<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Backup;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Backup\ComponentSelection;
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Domain\Archive\ArchiveEntryValidator;
use Vtinnovations\GuardianTypo3\Domain\Filesystem\PathNormalizer;
use Vtinnovations\GuardianTypo3\Infrastructure\Backup\BackupStorage;
use Vtinnovations\GuardianTypo3\Infrastructure\Backup\FileCollector;
use Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery\FakeWorkingDirectory;

/**
 * Proves the backup collector now CAPTURES Composer path-repository symlinks
 * (their real contents), while still refusing symlinks that escape the project
 * or would loop — and never recurses forever.
 */
final class FileCollectorSymlinkTest extends TestCase
{
    private string $project;
    private FileCollector $collector;

    protected function setUp(): void
    {
        $this->project = realpath(sys_get_temp_dir()) . '/guardian-fc-' . bin2hex(random_bytes(6));

        // Local path packages (the real sources).
        mkdir($this->project . '/packages/guardian-typo3/src', 0o755, true);
        file_put_contents($this->project . '/packages/guardian-typo3/src/Guardian.php', '<?php');
        mkdir($this->project . '/packages/brickieit', 0o755, true);
        file_put_contents($this->project . '/packages/brickieit/composer.json', '{}');

        // vendor tree with Composer-style symlinks.
        mkdir($this->project . '/vendor/vtinnovations', 0o755, true);
        mkdir($this->project . '/vendor/typo3/cms-cli', 0o755, true);
        mkdir($this->project . '/vendor/bin', 0o755, true);
        file_put_contents($this->project . '/vendor/typo3/cms-cli/typo3', '#!/usr/bin/env php');
        file_put_contents($this->project . '/vendor/autoload.php', '<?php');

        symlink('../../packages/guardian-typo3', $this->project . '/vendor/vtinnovations/guardian-typo3');
        symlink('../../packages/brickieit', $this->project . '/vendor/vtinnovations/brickieit');
        symlink('../typo3/cms-cli/typo3', $this->project . '/vendor/bin/typo3');
        symlink('../../', $this->project . '/vendor/loopself');      // points at project root → loop
        symlink('/etc', $this->project . '/vendor/ext');             // escapes the project

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
        $normalizer = new PathNormalizer();
        $storage = new BackupStorage(new FakeWorkingDirectory($this->project . '/var/guardian'), $normalizer);
        $this->collector = new FileCollector($env, $storage, new ArchiveEntryValidator(), $normalizer);
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->project));
    }

    /**
     * @return array{entries: list<string>, log: list<string>}
     */
    private function collectVendor(): array
    {
        $log = [];
        $entries = [];
        foreach ($this->collector->collect(ComponentSelection::fromRequest(['vendor' => true]), function (string $l) use (&$log): void {
            $log[] = $l;
        }) as $item) {
            $entries[] = $item['entry'];
        }

        return ['entries' => $entries, 'log' => $log];
    }

    #[Test]
    public function capturesPathRepositoryPackageContents(): void
    {
        $r = $this->collectVendor();
        self::assertContains('vendor/vtinnovations/guardian-typo3/src/Guardian.php', $r['entries']);
        self::assertContains('vendor/vtinnovations/brickieit/composer.json', $r['entries']);
    }

    #[Test]
    public function capturesBinProxyFileSymlink(): void
    {
        self::assertContains('vendor/bin/typo3', $this->collectVendor()['entries']);
    }

    #[Test]
    public function skipsLoopAndExternalSymlinks(): void
    {
        $r = $this->collectVendor();
        foreach ($r['entries'] as $entry) {
            self::assertStringStartsNotWith('vendor/loopself', $entry);
            self::assertStringStartsNotWith('vendor/ext', $entry);
        }
        $joined = implode("\n", $r['log']);
        self::assertStringContainsString('would cause a loop', $joined);
        self::assertStringContainsString('escapes the project', $joined);
        self::assertStringContainsString('Followed path symlink: vendor/vtinnovations/guardian-typo3', $joined);
    }

    #[Test]
    public function terminates(): void
    {
        // If loop protection were missing this would recurse forever; reaching the
        // assertion proves it terminates.
        self::assertNotEmpty($this->collectVendor()['entries']);
    }
}
