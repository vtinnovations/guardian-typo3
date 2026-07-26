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
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\AtomicDirectorySwitch;

final class AtomicDirectorySwitchTest extends TestCase
{
    private string $base;
    private AtomicDirectorySwitch $switch;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/guardian-switch-' . bin2hex(random_bytes(6));
        mkdir($this->base, 0o755, true);
        $this->switch = new AtomicDirectorySwitch();
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->base));
    }

    private function makeDir(string $name, string $marker): string
    {
        $dir = $this->base . '/' . $name;
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/marker.txt', $marker);

        return $dir;
    }

    #[Test]
    public function switchInPreservesOldAndInstallsStaged(): void
    {
        $live = $this->makeDir('vendor', 'OLD');
        $staged = $this->makeDir('staged', 'NEW');
        $old = $this->base . '/old-vendor';

        $this->switch->switchIn($live, $staged, $old);

        self::assertFileExists($live . '/marker.txt');
        self::assertSame('NEW', file_get_contents($live . '/marker.txt'), 'staged is now live');
        self::assertSame('OLD', file_get_contents($old . '/marker.txt'), 'previous is retained');
        self::assertDirectoryDoesNotExist($staged);
    }

    #[Test]
    public function neverDeletesLiveBeforeReplacement(): void
    {
        // If staged does not exist, the switch must refuse and leave live intact.
        $live = $this->makeDir('vendor', 'OLD');
        $this->expectException(GuardianException::class);
        try {
            $this->switch->switchIn($live, $this->base . '/missing-staged', $this->base . '/old-vendor');
        } finally {
            self::assertSame('OLD', file_get_contents($live . '/marker.txt'), 'live vendor untouched');
        }
    }

    #[Test]
    public function revertRestoresPreviousVendor(): void
    {
        $live = $this->makeDir('vendor', 'BROKEN');
        $old = $this->makeDir('old-vendor', 'GOOD');

        $this->switch->revert($live, $old, $this->base . '/failed-vendor');

        self::assertSame('GOOD', file_get_contents($live . '/marker.txt'));
        self::assertFileExists($this->base . '/failed-vendor/marker.txt');
    }

    #[Test]
    public function refusesToOverwriteAnExistingRetainedPath(): void
    {
        $live = $this->makeDir('vendor', 'OLD');
        $staged = $this->makeDir('staged', 'NEW');
        $old = $this->makeDir('old-vendor', 'PREEXISTING');

        $this->expectException(GuardianException::class);
        $this->switch->switchIn($live, $staged, $old);
    }

    #[Test]
    public function sameDirectoryIsAtomicallyRenamable(): void
    {
        self::assertTrue($this->switch->canAtomicallyRename($this->base . '/vendor', $this->base . '/old-vendor'));
    }
}
