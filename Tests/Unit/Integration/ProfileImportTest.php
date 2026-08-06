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

/**
 * Provisioning, exercised by actually running it.
 *
 * The keys are handed over as an issuer profile, and the step that consumes it is the one place a
 * wrong key can enter the build. Each refusal below is a mistake that has a plausible path to
 * happening — the wrong product's profile, a file altered in transit, a fingerprint nobody checked
 * — so each is asserted rather than assumed.
 *
 * Every case runs against a throwaway copy of the repository. The real key ring is never touched.
 */
final class ProfileImportTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/guardian-profile-' . bin2hex(random_bytes(6));
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
     * A profile in exactly the shape the issuer's `--format=json` export produces.
     *
     * @param array<string, mixed> $overrides
     * @return array{path: string, fingerprint: string}
     */
    private function profile(array $overrides = []): array
    {
        $pair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($pair);
        $fingerprint = hash('sha256', $publicKey);

        $profile = $overrides + [
            'profile_version' => 1,
            'issuer' => 'v-t.one',
            'generated_at' => time(),
            'keys' => [[
                'key_id' => 'vtone-2026a',
                'signature_algorithm' => 'ed25519',
                'public_key_base64' => base64_encode($publicKey),
                'fingerprint_sha256' => $fingerprint,
                'fingerprint_short' => substr($fingerprint, 0, 16),
                'status' => 'active',
            ]],
            'signatures' => [
                'license_payload' => ['key_ids' => ['vtone-2026a'], 'canonicalisation' => 'vt-one/canonical-json-v1', 'names_key_id' => false],
                'integrity_envelope' => ['key_ids' => ['vtone-2026a'], 'canonicalisation' => 'vt-one/canonical-json-v1', 'names_key_id' => true],
                'update_request' => ['key_ids' => ['vtone-2026a'], 'canonicalisation' => 'vt-one/request-sig-v1', 'names_key_id' => true],
            ],
            'product' => ['project_slug' => 'guardian', 'product_id' => 'vt-guardian'],
        ];

        $path = $this->workspace . '/profile-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, (string) json_encode($profile, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        return ['path' => $path, 'fingerprint' => substr($fingerprint, 0, 16)];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function import(string $repository, string ...$arguments): array
    {
        $output = [];
        $status = 0;
        exec(
            'php ' . escapeshellarg($repository . '/Build/import-integration-profile.php') . ' '
            . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1',
            $output,
            $status
        );

        return [$status, implode("\n", $output)];
    }

    /** A throwaway repository copy whose key ring can be written to. */
    private function repositoryCopy(): string
    {
        $copy = $this->workspace . '/repo';
        mkdir($copy, 0o700, true);
        foreach (['Classes', 'Build'] as $directory) {
            exec(sprintf('cp -R %s %s', escapeshellarg($this->root() . '/' . $directory), escapeshellarg($copy . '/')));
        }
        $this->resetRing($copy);

        return $copy;
    }

    /**
     * Empties the key ring of a repository copy, so the importer runs against the
     * state a fresh build has. The shipped repository keeps its pinned key; only
     * the throwaway copy is reset, and the reset is asserted rather than assumed.
     */
    private function resetRing(string $repository): void
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
            self::assertSame(1, $count, 'the ring could not be reset: ' . $method);
            $source = (string) $replaced;
        }

        file_put_contents($path, $source);
        self::assertTrue($this->ringOf($repository)['empty'], 'the copy must start with an empty ring');
    }


    private function ringOf(string $repository): array
    {
        $script = <<<'PHP'
        spl_autoload_register(function (string $c) {
            $p = 'Vtinnovations\\GuardianTypo3\\';
            if (!str_starts_with($c, $p)) { return; }
            $f = getenv('REPO') . '/Classes/' . str_replace('\\', '/', substr($c, strlen($p))) . '.php';
            if (is_file($f)) { require $f; }
        });
        $r = new Vtinnovations\GuardianTypo3\Infrastructure\Version\ReleaseKeyring();
        echo json_encode(['empty' => $r->isEmpty(), 'ids' => $r->keyIds(), 'problems' => $r->productionReadiness()]);
        PHP;

        $output = [];
        exec('REPO=' . escapeshellarg($repository) . ' php -r ' . escapeshellarg($script), $output);

        return json_decode(implode('', $output), true) ?? [];
    }

    // ── The success path ────────────────────────────────────────────────────

    #[Test]
    public function aConfirmedProfilePinsItsKeyAndClearsTheReleaseGate(): void
    {
        $repository = $this->repositoryCopy();
        $profile = $this->profile();

        [$status, $output] = $this->import($repository, '--profile=' . $profile['path'], '--expect-fingerprint=' . $profile['fingerprint']);
        self::assertSame(0, $status, $output);

        $ring = $this->ringOf($repository);
        self::assertFalse($ring['empty']);
        self::assertSame(['vtone-2026a'], $ring['ids']);
        self::assertSame([], $ring['problems'], 'the release gate must now pass');
    }

    #[Test]
    public function aDryRunReportsTheKeyAndWritesNothing(): void
    {
        $repository = $this->repositoryCopy();
        $profile = $this->profile();

        [$status, $output] = $this->import($repository, '--profile=' . $profile['path'], '--dry-run');

        self::assertSame(0, $status);
        self::assertStringContainsString($profile['fingerprint'], $output);
        self::assertTrue($this->ringOf($repository)['empty']);
    }

    // ── Refusals ────────────────────────────────────────────────────────────

    #[Test]
    public function aProfileForAnotherProductIsRefused(): void
    {
        $repository = $this->repositoryCopy();
        $profile = $this->profile(['product' => ['project_slug' => 'brickie', 'product_id' => 'vt-brickie']]);

        [$status, $output] = $this->import($repository, '--profile=' . $profile['path'], '--expect-fingerprint=' . $profile['fingerprint']);

        self::assertSame(1, $status);
        self::assertStringContainsString('not "guardian"', $output);
        self::assertTrue($this->ringOf($repository)['empty']);
    }

    #[Test]
    public function aFingerprintThatDoesNotMatchIsRefused(): void
    {
        $repository = $this->repositoryCopy();
        $profile = $this->profile();

        [$status, $output] = $this->import($repository, '--profile=' . $profile['path'], '--expect-fingerprint=' . str_repeat('a', 16));

        self::assertSame(1, $status);
        self::assertStringContainsString('no key in this profile matches the fingerprint', $output);
        self::assertTrue($this->ringOf($repository)['empty']);
    }

    #[Test]
    public function aProfileWhoseOwnFingerprintDisagreesWithItsKeyIsRefused(): void
    {
        // A file altered in transit: the key was swapped but the stated fingerprint was not.
        $repository = $this->repositoryCopy();
        $profile = $this->profile();
        $data = json_decode((string) file_get_contents($profile['path']), true);
        $data['keys'][0]['fingerprint_short'] = str_repeat('b', 16);
        file_put_contents($profile['path'], (string) json_encode($data));

        [$status, $output] = $this->import($repository, '--profile=' . $profile['path']);

        self::assertSame(1, $status);
        self::assertStringContainsString('inconsistent', $output);
        self::assertTrue($this->ringOf($repository)['empty']);
    }

    #[Test]
    public function aProfileCarryingSecretMaterialIsRefused(): void
    {
        $repository = $this->repositoryCopy();
        $profile = $this->profile();
        $data = json_decode((string) file_get_contents($profile['path']), true);
        $data['keys'][0]['secret_key'] = base64_encode(random_bytes(64));
        file_put_contents($profile['path'], (string) json_encode($data));

        [$status, $output] = $this->import($repository, '--profile=' . $profile['path']);

        self::assertSame(1, $status);
        self::assertStringContainsString('public material only', $output);
        self::assertTrue($this->ringOf($repository)['empty']);
    }

    #[Test]
    public function aPrivateKeyOfferedAsAPublicOneIsRefused(): void
    {
        $repository = $this->repositoryCopy();
        $profile = $this->profile();
        $data = json_decode((string) file_get_contents($profile['path']), true);
        $data['keys'][0]['public_key_base64'] = base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        unset($data['keys'][0]['fingerprint_short'], $data['keys'][0]['fingerprint_sha256']);
        file_put_contents($profile['path'], (string) json_encode($data));

        [$status, $output] = $this->import($repository, '--profile=' . $profile['path']);

        self::assertSame(1, $status);
        self::assertStringContainsString('private key', $output);
        self::assertTrue($this->ringOf($repository)['empty']);
    }

    #[Test]
    public function anUnsupportedCanonicalisationIsRefused(): void
    {
        // A profile announcing a rule this build does not implement would produce a package that
        // verifies nothing, which is worse than refusing to build it.
        $repository = $this->repositoryCopy();
        $profile = $this->profile();
        $data = json_decode((string) file_get_contents($profile['path']), true);
        $data['signatures']['license_payload']['canonicalisation'] = 'vt-one/canonical-json-v2';
        file_put_contents($profile['path'], (string) json_encode($data));

        [$status, $output] = $this->import($repository, '--profile=' . $profile['path']);

        self::assertSame(1, $status);
        self::assertStringContainsString('unsupported canonicalisation', $output);
        self::assertTrue($this->ringOf($repository)['empty']);
    }

    #[Test]
    public function anUnsupportedAlgorithmIsRefused(): void
    {
        $repository = $this->repositoryCopy();
        $profile = $this->profile();
        $data = json_decode((string) file_get_contents($profile['path']), true);
        $data['keys'][0]['signature_algorithm'] = 'rsa';
        file_put_contents($profile['path'], (string) json_encode($data));

        [$status, $output] = $this->import($repository, '--profile=' . $profile['path']);

        self::assertSame(1, $status);
        self::assertStringContainsString('only ed25519', $output);
    }

    #[Test]
    public function importingOverAnAlreadyPinnedRingIsRefused(): void
    {
        // Replacing a trusted key must be a deliberate reset, never a side effect of running the
        // importer twice.
        $repository = $this->repositoryCopy();
        $first = $this->profile();
        [$status] = $this->import($repository, '--profile=' . $first['path'], '--expect-fingerprint=' . $first['fingerprint']);
        self::assertSame(0, $status);

        $second = $this->profile();
        [$status, $output] = $this->import($repository, '--profile=' . $second['path'], '--expect-fingerprint=' . $second['fingerprint']);

        self::assertSame(1, $status);
        self::assertStringContainsString('already holds pinned material', $output);
        self::assertSame(['vtone-2026a'], $this->ringOf($repository)['ids']);
    }

    #[Test]
    public function aMissingOrUnreadableProfileIsRefused(): void
    {
        $repository = $this->repositoryCopy();

        [$status, $output] = $this->import($repository, '--profile=' . $this->workspace . '/nope.json');

        self::assertSame(1, $status);
        self::assertStringContainsString('could not be read', $output);
    }

    #[Test]
    public function theShippedKeyRingCarriesTheApprovedKey(): void
    {
        // The importer's whole purpose is this outcome, so the shipped repository is
        // checked directly: the approved key is pinned and the release gate is clear.
        $ring = $this->ringOf($this->root());

        self::assertFalse($ring['empty'], 'the approved verification key is not pinned');
        self::assertSame(['vtone-2026a'], $ring['ids']);
        self::assertSame([], $ring['problems']);
    }
}
