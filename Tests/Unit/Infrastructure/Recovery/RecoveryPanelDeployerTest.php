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
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\RecoveryPanelConfigStore;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\RecoveryPanelDeployer;

final class RecoveryPanelDeployerTest extends TestCase
{
    private string $projectRoot;
    private string $publicDir;
    private string $extensionRoot;
    private RecoveryPanelConfigStore $config;
    private RecoveryPanelDeployer $deployer;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/guardian-dep-' . bin2hex(random_bytes(6));
        $this->publicDir = $this->projectRoot . '/public';
        $this->extensionRoot = $this->projectRoot . '/ext';
        mkdir($this->publicDir, 0o755, true);
        mkdir($this->extensionRoot . '/Resources/Private/Recovery', 0o755, true);
        file_put_contents(
            $this->extensionRoot . '/Resources/Private/Recovery/_guardian-recovery.php',
            "<?php\n// " . RecoveryPanelDeployer::SIGNATURE . "\necho 'panel';\n"
        );

        $environment = new class($this->publicDir) implements ProjectEnvironmentInterface {
            public function __construct(private readonly string $public) {}
            public function typo3Version(): string { return '13.4.9'; }
            public function projectPath(): string { return \dirname($this->public); }
            public function varPath(): string { return \dirname($this->public) . '/var'; }
            public function publicPath(): string { return $this->public; }
            public function isComposerMode(): bool { return true; }
            public function phpVersion(): string { return \PHP_VERSION; }
            public function loadedPhpExtensions(): array { return []; }
        };

        $this->config = new RecoveryPanelConfigStore(new FakeWorkingDirectory($this->projectRoot . '/var/guardian'));
        $this->deployer = new RecoveryPanelDeployer($environment, $this->config, $this->extensionRoot);
    }

    #[Test]
    public function deployWritesAManagedEntrypointWithTheOwnershipMarker(): void
    {
        $this->config->setFilename('_guardian-recovery.php');
        $this->deployer->deploy();

        $target = $this->publicDir . '/_guardian-recovery.php';
        self::assertFileExists($target);
        self::assertStringContainsString(RecoveryPanelDeployer::SIGNATURE, (string) file_get_contents($target));
        self::assertTrue($this->deployer->isDeployed());
        self::assertTrue($this->deployer->isManaged($target));
    }

    #[Test]
    public function removeDeletesOnlyManagedFiles(): void
    {
        $this->config->setFilename('_guardian-recovery.php');
        $this->deployer->deploy();
        $this->deployer->remove();
        self::assertFileDoesNotExist($this->publicDir . '/_guardian-recovery.php');
    }

    #[Test]
    public function removeNeverDeletesAnUnmanagedFileWithTheSameName(): void
    {
        $this->config->setFilename('_guardian-recovery.php');
        $target = $this->publicDir . '/_guardian-recovery.php';
        file_put_contents($target, "<?php // a file the operator created themselves\n");

        $this->deployer->remove();

        self::assertFileExists($target);
        self::assertFalse($this->deployer->isManaged($target));
    }

    #[Test]
    public function deployRefusesToOverwriteAnUnmanagedFile(): void
    {
        $this->config->setFilename('_guardian-recovery.php');
        file_put_contents($this->publicDir . '/_guardian-recovery.php', "<?php // not ours\n");

        $this->expectException(GuardianException::class);
        $this->deployer->deploy();
    }

    #[Test]
    public function changingFilenameRemovesThePreviousManagedEntrypoint(): void
    {
        $this->config->setFilename('_guardian-recovery.php');
        $this->deployer->deploy();
        self::assertFileExists($this->publicDir . '/_guardian-recovery.php');

        $this->config->setFilename('rescue.php');
        $this->deployer->deploy('_guardian-recovery.php');

        self::assertFileExists($this->publicDir . '/rescue.php');
        self::assertFileDoesNotExist($this->publicDir . '/_guardian-recovery.php');
    }
}
