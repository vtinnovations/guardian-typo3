<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\ReleaseKeyring;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\SigningKey;

/**
 * The release pipeline, exercised by actually running it.
 *
 * Two things are checked. First, that the build refuses to produce anything at
 * all while no verification key is pinned — the gate is the whole point, so a
 * test that only asserted it "exists" would be worthless. Second, that once a key
 * *is* pinned the pipeline produces a sound artefact: no development content, no
 * signing capability, every file parsing, and a manifest that matches.
 *
 * The second case is run against a throwaway copy of the repository with a
 * generated key pinned into it. That copy is what makes it possible to test the
 * success path without adding a switch that could disable the gate in production
 * — there is deliberately no such switch.
 */
final class ReleaseArtefactTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/guardian-release-' . bin2hex(random_bytes(6));
        mkdir($this->workspace, 0o700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workspace)) {
            exec('rm -rf ' . escapeshellarg($this->workspace));
        }
    }

    private function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function build(string $script, string $target): array
    {
        $output = [];
        $status = 0;
        exec('php ' . escapeshellarg($script) . ' ' . escapeshellarg($target) . ' 2>&1', $output, $status);

        return [$status, implode("\n", $output)];
    }

    #[Test]
    public function theBuildRefusesWhenNoVerificationKeyIsPinned(): void
    {
        // The gate is the whole point, so it is exercised against a copy whose ring
        // has been emptied rather than trusted to be true by inspection.
        $copy = $this->workspaceCopy(false);

        [$status, $output] = $this->build($copy . '/Build/release.php', $this->workspace . '/dist');

        self::assertSame(1, $status, 'the build must fail, not warn');
        self::assertStringContainsString('BUILD FAILED', $output);
        self::assertStringContainsString('signing_key_store_empty', $output);
        self::assertDirectoryDoesNotExist($this->workspace . '/dist/Classes');
    }

    #[Test]
    public function theShippedRepositoryPassesTheGateItEnforces(): void
    {
        [$status, $output] = $this->build($this->root() . '/Build/release.php', $this->workspace . '/dist');

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('vtone-2026a', $output);
        self::assertFileExists($this->workspace . '/dist/Classes/Middleware/RestEndpointMiddleware.php');
    }

    #[Test]
    public function withAKeyPinnedTheArtefactIsSound(): void
    {
        $copy = $this->workspaceCopy(true);
        [$status, $output] = $this->build($copy . '/Build/release.php', $this->workspace . '/dist');

        self::assertSame(0, $status, $output);
        $dist = $this->workspace . '/dist';

        // The product is there.
        self::assertFileExists($dist . '/Classes/Middleware/RestEndpointMiddleware.php');
        self::assertFileExists($dist . '/Configuration/Services.yaml');
        self::assertFileExists($dist . '/Configuration/RequestMiddlewares.php');
        self::assertFileExists($dist . '/composer.json');

        // Development is not.
        self::assertDirectoryDoesNotExist($dist . '/Tests');
        self::assertDirectoryDoesNotExist($dist . '/Build');
        self::assertDirectoryDoesNotExist($dist . '/.internal');
        self::assertFileDoesNotExist($dist . '/phpunit.xml.dist');

        // The framework metadata a TYPO3 extension is wired by survives intact,
        // because it is resolved by fully-qualified name.
        $services = (string) file_get_contents($dist . '/Configuration/Services.yaml');
        self::assertStringContainsString('Vtinnovations\\GuardianTypo3\\Middleware\\RestEndpointMiddleware', $services);

        $middleware = (string) file_get_contents($dist . '/Classes/Middleware/RestEndpointMiddleware.php');
        self::assertStringContainsString('namespace Vtinnovations\\GuardianTypo3\\Middleware;', $middleware);
        self::assertStringContainsString('class RestEndpointMiddleware', $middleware);
    }

    #[Test]
    public function theShippedCodeCarriesNoExplanatoryComments(): void
    {
        $copy = $this->workspaceCopy(true);
        [$status] = $this->build($copy . '/Build/release.php', $this->workspace . '/dist');
        self::assertSame(0, $status);

        $shipped = (string) file_get_contents($this->workspace . '/dist/Classes/Infrastructure/Manifest/SealedPackage.php');

        self::assertStringNotContainsString('exact-byte tripwire', $shipped);
        self::assertStringNotContainsString('/**', $shipped);
        self::assertStringNotContainsString('// ', $shipped);
        // The behaviour is unchanged, only the prose is gone.
        self::assertStringContainsString('hash_equals', $shipped);
    }

    #[Test]
    public function theArtefactShipsAManifestThatMatchesIt(): void
    {
        $copy = $this->workspaceCopy(true);
        [$status] = $this->build($copy . '/Build/release.php', $this->workspace . '/dist');
        self::assertSame(0, $status);

        $dist = $this->workspace . '/dist';
        $manifest = json_decode((string) file_get_contents($dist . '/Resources/Private/release-manifest.json'), true);

        self::assertIsArray($manifest);
        self::assertSame('sha256', $manifest['algorithm']);
        self::assertNotEmpty($manifest['files']);

        foreach ($manifest['files'] as $relative => $digest) {
            self::assertFileExists($dist . '/' . $relative);
            self::assertSame($digest, hash_file('sha256', $dist . '/' . $relative), $relative);
        }

        // The security-relevant files are the ones a customer would want to check.
        foreach ([
            'Classes/Infrastructure/Version/ReleaseKeyring.php',
            'Classes/Infrastructure/Manifest/SealedPackage.php',
            'Classes/Infrastructure/Configuration/SealedRecordStore.php',
            'Classes/Middleware/RestEndpointMiddleware.php',
            'Configuration/Services.yaml',
        ] as $expected) {
            self::assertArrayHasKey($expected, $manifest['files'], $expected);
        }
    }

    #[Test]
    public function theArtefactContainsNoSigningCapabilityAndNoSymbolMap(): void
    {
        $copy = $this->workspaceCopy(true);
        [$status] = $this->build($copy . '/Build/release.php', $this->workspace . '/dist');
        self::assertSame(0, $status);

        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->workspace . '/dist',
            \FilesystemIterator::SKIP_DOTS
        ));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (str_contains($file->getBasename(), 'symbol-map')) {
                $offenders[] = $file->getBasename();
            }
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            foreach (['sodium_crypto_sign_detached', 'sodium_crypto_sign_secretkey', 'PRIVATE KEY'] as $marker) {
                if (str_contains($source, $marker)) {
                    $offenders[] = $file->getBasename() . ': ' . $marker;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * A throwaway copy of the repository with a key pinned through the real
     * pinning tool, so the success path of the pipeline can be exercised.
     *
     * The key is generated here and never leaves this directory. Using the tool
     * rather than editing the ring by hand means this also covers the tool, the
     * fingerprint declaration and the release gate as one flow.
     */
    private function workspaceCopy(bool $pinned): string
    {
        $copy = $this->workspace . '/repo';
        mkdir($copy, 0o700, true);

        foreach (['Classes', 'Configuration', 'Resources', 'Documentation', 'Build'] as $directory) {
            exec(sprintf('cp -R %s %s', escapeshellarg($this->root() . '/' . $directory), escapeshellarg($copy . '/')));
        }
        foreach (['composer.json', 'LICENSE', 'README.md'] as $file) {
            copy($this->root() . '/' . $file, $copy . '/' . $file);
        }
        foreach (['ext_localconf.php'] as $optional) {
            if (is_file($this->root() . '/' . $optional)) {
                copy($this->root() . '/' . $optional, $copy . '/' . $optional);
            }
        }
        // The build only needs the autoloader; symlinking avoids copying it.
        symlink($this->root() . '/vendor', $copy . '/vendor');

        // The copy starts from the shipped state, which already pins a key. It is
        // emptied first so the pinning tool runs the same path a fresh build does.
        $this->emptyRing($copy);
        if (!$pinned) {
            return $copy;
        }

        $pair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($pair));
        $fingerprint = substr(hash('sha256', sodium_crypto_sign_publickey($pair)), 0, 16);

        // An identifier that does not look disposable, because the release check
        // refuses those outright — a rule asserted separately.
        $output = [];
        $status = 0;
        exec(sprintf(
            'php %s --key-id=vtone-2026a --public-key=%s --fingerprint=%s 2>&1',
            escapeshellarg($copy . '/Build/pin-verification-key.php'),
            escapeshellarg($publicKey),
            escapeshellarg($fingerprint),
        ), $output, $status);

        self::assertSame(0, $status, 'pinning failed: ' . implode("\n", $output));

        return $copy;
    }

    /** Returns a repository copy's key ring to the state a fresh build has. */
    private function emptyRing(string $repository): void
    {
        $path = $repository . '/Classes/Infrastructure/Version/ReleaseKeyring.php';
        $source = (string) file_get_contents($path);

        foreach (['material', 'declaredFingerprints'] as $method) {
            $replaced = preg_replace(
                '/(private static function ' . $method . '\(\): array\s*\{\s*)return \[.*?\];/s',
                '$1return [];',
                $source,
                1,
                $count
            );
            self::assertSame(1, $count, 'the ring could not be emptied: ' . $method);
            $source = (string) $replaced;
        }

        file_put_contents($path, $source);
    }

    #[Test]
    public function theToolRefusesAKeyThatDoesNotMatchItsFingerprint(): void
    {
        $publicKey = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

        $output = [];
        $status = 0;
        exec(sprintf(
            'php %s --key-id=vtone-2026a --public-key=%s --fingerprint=%s 2>&1',
            escapeshellarg($this->root() . '/Build/pin-verification-key.php'),
            escapeshellarg($publicKey),
            escapeshellarg('deadbeefdeadbeef'),
        ), $output, $status);

        self::assertSame(1, $status);
        self::assertStringContainsString('does not match the fingerprint', implode("\n", $output));
    }

    #[Test]
    public function theToolRefusesAPrivateKey(): void
    {
        $privateKey = base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));

        $output = [];
        $status = 0;
        exec(sprintf(
            'php %s --key-id=vtone-2026a --public-key=%s --fingerprint=%s 2>&1',
            escapeshellarg($this->root() . '/Build/pin-verification-key.php'),
            escapeshellarg($privateKey),
            escapeshellarg('0000000000000000'),
        ), $output, $status);

        self::assertSame(1, $status);
        self::assertStringContainsString('private', implode("\n", $output));
    }

    #[Test]
    public function aPinnedKeyWithNoDeclaredFingerprintFailsTheReleaseCheck(): void
    {
        // Pinning the material without also declaring the fingerprint means the
        // key was never cross-checked, so the build must not proceed.
        $key = SigningKey::pin('vtone-2026a', 'ed25519', base64_encode(
            sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())
        ));
        self::assertNotNull($key);

        $ring = new ReleaseKeyring([$key], []);

        self::assertStringContainsString('unverified_key', implode(' ', $ring->productionReadiness()));
    }

    #[Test]
    public function aPinnedKeyWhoseFingerprintDisagreesFailsTheReleaseCheck(): void
    {
        $key = SigningKey::pin('vtone-2026a', 'ed25519', base64_encode(
            sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())
        ));
        self::assertNotNull($key);

        $ring = new ReleaseKeyring([$key], ['vtone-2026a' => str_repeat('a', 16)]);

        self::assertStringContainsString('fingerprint_mismatch', implode(' ', $ring->productionReadiness()));
    }
}
