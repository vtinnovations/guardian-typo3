<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Archive;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\Archive\ArchiveEntryValidator;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Archive\ZipSafetyInspector;

final class ZipSafetyInspectorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip is not available.');
        }
        $this->dir = sys_get_temp_dir() . '/guardian-zip-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function inspector(): ZipSafetyInspector
    {
        return new ZipSafetyInspector(new ArchiveEntryValidator());
    }

    /**
     * @param array<string, string> $entries
     */
    private function makeZip(array $entries): string
    {
        $path = $this->dir . '/a-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    #[Test]
    public function validatesAndExtractsASafeArchive(): void
    {
        $zip = $this->makeZip(['my_ext/composer.json' => '{"name":"local/my_ext"}', 'my_ext/ext_localconf.php' => '<?php']);
        $stats = $this->inspector()->validate($zip);
        self::assertSame(2, $stats['entries']);

        $target = $this->dir . '/out';
        $this->inspector()->extractTo($zip, $target);
        self::assertFileExists($target . '/my_ext/composer.json');
    }

    #[Test]
    public function rejectsPathTraversal(): void
    {
        $zip = $this->makeZip(['../evil.txt' => 'x']);
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('zip_unsafe_path');
        $this->inspector()->validate($zip);
    }

    #[Test]
    public function rejectsAnEmptyArchive(): void
    {
        $zip = $this->makeZip([]);
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('zip_empty');
        $this->inspector()->validate($zip);
    }

    #[Test]
    public function rejectsADecompressionBomb(): void
    {
        // 3 MB of zero bytes compresses to almost nothing → ratio far over the cap.
        $zip = $this->makeZip(['bomb.bin' => str_repeat("\0", 3 * 1024 * 1024)]);
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('zip_bomb_ratio');
        $this->inspector()->validate($zip);
    }

    #[Test]
    public function rejectsAnInvalidZip(): void
    {
        $path = $this->dir . '/not.zip';
        file_put_contents($path, 'this is not a zip');
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('zip_invalid');
        $this->inspector()->validate($path);
    }
}
