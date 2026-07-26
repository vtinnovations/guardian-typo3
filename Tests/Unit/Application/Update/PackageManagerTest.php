<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Application\Update;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\Typo3ExtensionStateInterface;
use Vtinnovations\GuardianTypo3\Application\Update\PackageManager;
use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Filesystem\PathNormalizer;
use Vtinnovations\GuardianTypo3\Domain\Job\Job;
use Vtinnovations\GuardianTypo3\Domain\Job\JobType;
use Vtinnovations\GuardianTypo3\Infrastructure\Composer\PathRepositoryInspector;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\UpdateJobStore;
use Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery\FakeWorkingDirectory;

/**
 * Server-side classification + action-applicability policy for the Extensions
 * manager: precise category, which actions are even applicable to each package
 * class, and the machine reason when an applicable action cannot run.
 */
final class PackageManagerTest extends TestCase
{
    private string $project;
    private string $var;
    private \DateTimeImmutable $now;
    /** @var array<string, bool> */
    private array $inactive = [];

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-07-26T12:00:00+00:00');
        $this->project = sys_get_temp_dir() . '/guardian-pm-' . bin2hex(random_bytes(6));
        $this->var = $this->project . '/var-guardian';
        mkdir($this->project . '/vendor/composer', 0o755, true);
        mkdir($this->var, 0o755, true);

        file_put_contents($this->project . '/composer.json', json_encode([
            'require' => [
                'typo3/cms-core' => '^13.4',
                'typo3/cms-backend' => '^13.4',
                'vtinnovations/guardian-typo3' => '^1.0',
                'acme/blog' => '^1.0',
                'acme/core-lib' => '^1.0',
                'acme/widget' => '^2.0',
                'psr/log' => '^3.0',
            ],
        ]));

        file_put_contents($this->project . '/vendor/composer/installed.json', json_encode([
            'packages' => [
                ['name' => 'typo3/cms-core', 'type' => 'typo3-cms-framework', 'version' => 'v13.4.9', 'install-path' => '../typo3/cms-core'],
                ['name' => 'typo3/cms-backend', 'type' => 'typo3-cms-framework', 'version' => 'v13.4.9', 'install-path' => '../typo3/cms-backend'],
                ['name' => 'vtinnovations/guardian-typo3', 'type' => 'typo3-cms-extension', 'version' => '1.0.0', 'install-path' => '../vtinnovations/guardian-typo3', 'extra' => ['typo3/cms' => ['extension-key' => 'guardian_typo3']]],
                ['name' => 'acme/blog', 'type' => 'typo3-cms-extension', 'version' => '1.2.0', 'install-path' => '../acme/blog', 'extra' => ['typo3/cms' => ['extension-key' => 'acme_blog']], 'require' => ['acme/blog-lib' => '^1.0', 'acme/core-lib' => '^1.0']],
                ['name' => 'acme/blog-lib', 'type' => 'library', 'version' => '1.0.0', 'install-path' => '../acme/blog-lib'],
                ['name' => 'acme/core-lib', 'type' => 'library', 'version' => '1.0.0', 'install-path' => '../acme/core-lib'],
                ['name' => 'acme/widget', 'type' => 'typo3-cms-extension', 'version' => '2.0.0', 'install-path' => '../../packages/widget', 'extra' => ['typo3/cms' => ['extension-key' => 'acme_widget']]],
                ['name' => 'psr/log', 'type' => 'library', 'version' => '3.0.0', 'install-path' => '../psr/log'],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->project));
    }

    private function manager(bool $busy = false): PackageManager
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
        $clock = new class($this->now) implements ClockInterface {
            public function __construct(private readonly \DateTimeImmutable $n) {}
            public function now(): \DateTimeImmutable { return $this->n; }
        };
        $store = new UpdateJobStore(new FakeWorkingDirectory($this->var), $clock);
        if ($busy) {
            $store->save(Job::queue($store->generateId(), JobType::Update, ['composer'], $this->now, ['update_mode' => 'selective']));
        }
        $extState = $this->fakeExtensionState();

        return new PackageManager($env, new PathRepositoryInspector(new PathNormalizer()), $store, $extState);
    }

    private function fakeExtensionState(): Typo3ExtensionStateInterface
    {
        return new class($this->inactive) implements Typo3ExtensionStateInterface {
            /** @param array<string, bool> $inactive */
            public function __construct(private array $inactive) {}
            public function isAvailable(): bool { return true; }
            public function isActive(string $extensionKey): bool { return !isset($this->inactive[$extensionKey]); }
            public function isProtected(string $extensionKey): bool { return false; }
            public function deactivate(string $extensionKey): void {}
            public function activate(string $extensionKey): void {}
        };
    }

    /**
     * @param array<string, array<string, mixed>> $updateMap
     * @return array<string, array<string, mixed>>
     */
    private function indexed(?array $updateMap = null, ?string $updateError = null): array
    {
        $out = [];
        foreach ($this->manager()->list($updateMap ?? [], $updateError)['packages'] as $p) {
            $out[$p['name']] = $p;
        }

        return $out;
    }

    #[Test]
    public function classifiesEveryPackageClassPrecisely(): void
    {
        $p = $this->indexed();
        self::assertSame('typo3_core', $p['typo3/cms-core']['category']);
        self::assertSame('typo3_system_extension', $p['typo3/cms-backend']['category']);
        self::assertSame('third_party_extension', $p['acme/blog']['category']);
        self::assertSame('local_extension', $p['acme/widget']['category']);
        self::assertSame('composer_library', $p['psr/log']['category']);
        self::assertTrue($p['psr/log']['is_transitive'] === false); // psr/log is a root require here
        self::assertTrue($p['acme/blog-lib']['is_transitive']);
    }

    #[Test]
    public function ordinaryLibraryHasNoDisableOrEnableAction(): void
    {
        $p = $this->indexed();
        self::assertFalse($p['psr/log']['actions']['disable']['applicable']);
        self::assertFalse($p['psr/log']['actions']['enable']['applicable']);
        self::assertFalse($p['acme/core-lib']['actions']['disable']['applicable']);
    }

    #[Test]
    public function transitiveDependencyHasNoRemoveAction(): void
    {
        $p = $this->indexed();
        self::assertFalse($p['acme/blog-lib']['actions']['remove']['applicable']);
        // A root package remains removable-applicable.
        self::assertTrue($p['acme/blog']['actions']['remove']['applicable']);
    }

    #[Test]
    public function realExtensionExposesDisableWhenActive(): void
    {
        $p = $this->indexed();
        self::assertTrue($p['acme/blog']['actions']['disable']['applicable']);
        self::assertTrue($p['acme/blog']['actions']['disable']['permitted']);
        self::assertFalse($p['acme/blog']['actions']['enable']['applicable']); // it is active
    }

    #[Test]
    public function disabledExtensionExposesEnableInsteadOfDisable(): void
    {
        $this->inactive = ['acme_blog' => true];
        $p = $this->indexed();
        self::assertFalse($p['acme/blog']['active']);
        self::assertTrue($p['acme/blog']['actions']['enable']['applicable']);
        self::assertFalse($p['acme/blog']['actions']['disable']['applicable']);
    }

    #[Test]
    public function compatibleUpdateMakesUpdateApplicableAndActive(): void
    {
        $map = ['acme/blog' => ['latest' => '1.3.0', 'has_update' => true, 'update_state' => 'update_available']];
        $p = $this->indexed($map);
        self::assertTrue($p['acme/blog']['has_update']);
        self::assertSame('update_available', $p['acme/blog']['update_state']);
        self::assertTrue($p['acme/blog']['actions']['update']['applicable']);
        self::assertTrue($p['acme/blog']['actions']['update']['permitted']);
        // A package without an update offers no Update button.
        self::assertFalse($p['psr/log']['actions']['update']['applicable']);
    }

    #[Test]
    public function metadataFailureIsReportedAsCheckFailedNotUpToDate(): void
    {
        $p = $this->indexed([], 'update_check_failed');
        self::assertSame('update_check_failed', $p['acme/blog']['update_state']);
        self::assertNotSame('up_to_date', $p['acme/blog']['update_state']);
    }

    #[Test]
    public function withoutMetadataStateIsUnavailableNotUpToDate(): void
    {
        $p = $this->indexed(); // no update map, no error
        self::assertSame('metadata_unavailable', $p['acme/blog']['update_state']);
    }

    #[Test]
    public function coreAndSystemExtensionsAreProtected(): void
    {
        $p = $this->indexed();
        self::assertFalse($p['typo3/cms-core']['actions']['disable']['applicable']);
        self::assertFalse($p['typo3/cms-core']['actions']['remove']['applicable']);
        self::assertFalse($p['typo3/cms-backend']['actions']['remove']['applicable']);

        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('core_update_use_full');
        $this->manager()->assertUpdatable('typo3/cms-core');
    }

    #[Test]
    public function systemExtensionRemovalIsRejected(): void
    {
        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('system_cannot_remove');
        $this->manager()->assertRemovable('typo3/cms-backend');
    }

    #[Test]
    public function guardianProtectsItself(): void
    {
        $p = $this->indexed();
        self::assertFalse($p['vtinnovations/guardian-typo3']['actions']['remove']['applicable']);
        self::assertFalse($p['vtinnovations/guardian-typo3']['actions']['disable']['applicable']);

        foreach (['assertRemovable', 'assertDisableable', 'assertUpdatable'] as $method) {
            try {
                $this->manager()->{$method}('vtinnovations/guardian-typo3');
                self::fail('expected guardian_self from ' . $method);
            } catch (GuardianException $e) {
                self::assertSame('guardian_self', $e->getMessage());
            }
        }
    }

    #[Test]
    public function rootPackageIsRemovableButRequiredDependencyIsNot(): void
    {
        $this->manager()->assertRemovable('acme/blog'); // root, nothing depends on it
        $this->addToAssertionCount(1);

        try {
            $this->manager()->assertRemovable('acme/core-lib'); // required by acme/blog
            self::fail('expected required_by_other');
        } catch (GuardianException $e) {
            self::assertSame('required_by_other', $e->getMessage());
        }

        $this->expectException(GuardianException::class);
        $this->expectExceptionMessage('transitive_dependency');
        $this->manager()->assertRemovable('acme/blog-lib');
    }

    #[Test]
    public function everyActionIsBlockedWhileAnOperationRuns(): void
    {
        $manager = $this->manager(true);
        $result = $manager->list(['acme/blog' => ['has_update' => true, 'update_state' => 'update_available', 'latest' => '1.3.0']]);
        self::assertTrue($result['operationInProgress']);
        foreach ($result['packages'] as $p) {
            foreach (['update', 'disable', 'enable', 'remove'] as $action) {
                if ($p['actions'][$action]['applicable'] === true) {
                    self::assertFalse($p['actions'][$action]['permitted'], $p['name'] . '.' . $action);
                    self::assertSame('operation_in_progress', $p['actions'][$action]['reason']);
                }
            }
        }
    }
}
