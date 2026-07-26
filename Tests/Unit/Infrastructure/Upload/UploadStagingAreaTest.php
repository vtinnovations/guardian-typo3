<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Upload;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Vtinnovations\GuardianTypo3\Domain\Archive\ArchiveEntryValidator;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Archive\ZipSafetyInspector;
use Vtinnovations\GuardianTypo3\Infrastructure\Upload\UploadStagingArea;
use Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery\FakeWorkingDirectory;

final class UploadStagingAreaTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        if (!class_exists(\ZipArchive::class) || !class_exists(\GuzzleHttp\Psr7\Utils::class)) {
            self::markTestSkipped('ext-zip or guzzlehttp/psr7 unavailable.');
        }
        $this->base = sys_get_temp_dir() . '/guardian-upl-' . bin2hex(random_bytes(6));
        mkdir($this->base, 0o755, true);
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->base));
    }

    private function area(): UploadStagingArea
    {
        return new UploadStagingArea(new FakeWorkingDirectory($this->base . '/wd'), new ZipSafetyInspector(new ArchiveEntryValidator()));
    }

    private function zipBytes(): string
    {
        $path = $this->base . '/ext-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('my_ext/composer.json', '{"name":"acme/my-ext","type":"typo3-cms-extension"}');
        $zip->addFromString('my_ext/ext_emconf.php', "<?php\n");
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function guzzleUpload(string $bytes, ?string $name = 'My Ext!.zip'): UploadedFileInterface
    {
        return new \GuzzleHttp\Psr7\UploadedFile(\GuzzleHttp\Psr7\Utils::streamFor($bytes), \strlen($bytes), \UPLOAD_ERR_OK, $name);
    }

    /**
     * A PSR-7 uploaded file whose moveTo() FAILS (cross-filesystem / open_basedir)
     * so the stream-copy fallback is exercised; getSize can be overridden.
     */
    private function fakeUpload(string $bytes, bool $moveThrows, ?int $sizeOverride = null): UploadedFileInterface
    {
        return new class($bytes, $moveThrows, $sizeOverride) implements UploadedFileInterface {
            public function __construct(private string $bytes, private bool $moveThrows, private ?int $sizeOverride) {}
            public function getStream(): StreamInterface { return \GuzzleHttp\Psr7\Utils::streamFor($this->bytes); }
            public function moveTo(string $targetPath): void
            {
                if ($this->moveThrows) {
                    throw new \RuntimeException('Uploaded file could not be moved to ' . $targetPath);
                }
                file_put_contents($targetPath, $this->bytes);
            }
            public function getSize(): ?int { return $this->sizeOverride ?? \strlen($this->bytes); }
            public function getError(): int { return \UPLOAD_ERR_OK; }
            public function getClientFilename(): ?string { return 'x.zip'; }
            public function getClientMediaType(): ?string { return 'application/zip'; }
        };
    }

    #[Test]
    public function stagesInsideTheProjectRuntimeWithARandomIdAndRestrictivePerms(): void
    {
        $staged = $this->area()->acceptUploadedFile($this->guzzleUpload($this->zipBytes()));

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $staged['token']);
        self::assertStringContainsString('/extensions/uploads/' . $staged['token'] . '/archive.zip', $staged['archive']);
        self::assertFileExists($staged['archive']);
        self::assertDirectoryExists($staged['extracted']);
        // Client filename is sanitised (spaces/illegal chars → underscore).
        self::assertSame('My_Ext_.zip', $staged['filename']);
        // Restrictive permissions — never 0777.
        self::assertNotSame('0777', substr(sprintf('%o', fileperms($staged['archive'])), -4));
    }

    #[Test]
    public function usesTheStreamCopyFallbackWhenMoveToFails(): void
    {
        $bytes = $this->zipBytes();
        $staged = $this->area()->acceptUploadedFile($this->fakeUpload($bytes, true));

        self::assertFileExists($staged['archive']);
        self::assertSame(\strlen($bytes), filesize($staged['archive']));
        self::assertDirectoryExists($staged['extracted']);
    }

    #[Test]
    public function detectsASizeMismatch(): void
    {
        $bytes = $this->zipBytes();
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('upload_size_mismatch');
        // Declared size lies (larger than the actual bytes).
        $this->area()->acceptUploadedFile($this->fakeUpload($bytes, false, \strlen($bytes) + 500));
    }

    #[Test]
    public function rejectsAnOversizeUploadBeforeMoving(): void
    {
        $upload = new class implements UploadedFileInterface {
            public function getStream(): StreamInterface { return \GuzzleHttp\Psr7\Utils::streamFor(''); }
            public function moveTo(string $targetPath): void {}
            public function getSize(): ?int { return 61 * 1024 * 1024; }
            public function getError(): int { return \UPLOAD_ERR_OK; }
            public function getClientFilename(): ?string { return 'big.zip'; }
            public function getClientMediaType(): ?string { return 'application/zip'; }
        };
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('upload_too_large');
        $this->area()->acceptUploadedFile($upload);
    }

    #[Test]
    public function rejectsAnIncompleteUpload(): void
    {
        $upload = new class implements UploadedFileInterface {
            public function getStream(): StreamInterface { return \GuzzleHttp\Psr7\Utils::streamFor(''); }
            public function moveTo(string $targetPath): void {}
            public function getSize(): ?int { return 10; }
            public function getError(): int { return \UPLOAD_ERR_PARTIAL; }
            public function getClientFilename(): ?string { return 'x.zip'; }
            public function getClientMediaType(): ?string { return null; }
        };
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('upload_incomplete');
        $this->area()->acceptUploadedFile($upload);
    }

    #[Test]
    public function reportsRootNotWritableWithAPreciseCode(): void
    {
        if (\function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('running as root ignores permission bits');
        }
        $root = $this->base . '/wd/extensions/uploads';
        mkdir($root, 0o500, true); // readable, NOT writable

        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('upload_root_not_writable');
        $this->area()->acceptUploadedFile($this->guzzleUpload($this->zipBytes()));
    }

    #[Test]
    public function eachUploadGetsAUniqueCryptographicId(): void
    {
        $a = $this->area()->acceptUploadedFile($this->guzzleUpload($this->zipBytes()));
        $b = $this->area()->acceptUploadedFile($this->guzzleUpload($this->zipBytes()));
        self::assertNotSame($a['token'], $b['token']);
    }

    #[Test]
    public function getAndCleanupRoundTrip(): void
    {
        $staged = $this->area()->acceptUploadedFile($this->guzzleUpload($this->zipBytes()));
        $found = $this->area()->get($staged['token']);
        self::assertSame($staged['archive'], $found['archive']);

        $this->area()->cleanup($staged['token']);
        self::assertDirectoryDoesNotExist(\dirname($staged['archive']));
    }
}
