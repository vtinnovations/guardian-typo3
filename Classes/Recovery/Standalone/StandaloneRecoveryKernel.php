<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Recovery\Standalone;

use Vtinnovations\GuardianTypo3\Application\Backup\BackupService;
use Vtinnovations\GuardianTypo3\Application\Backup\RetentionPolicy;
use Vtinnovations\GuardianTypo3\Application\Configuration\RuntimeConfigurationService;
use Vtinnovations\GuardianTypo3\Application\Recovery\BackupCatalog;
use Vtinnovations\GuardianTypo3\Application\Recovery\RecoveryDryRun;
use Vtinnovations\GuardianTypo3\Application\Recovery\RecoveryEmailNotifier;
use Vtinnovations\GuardianTypo3\Application\Recovery\RecoveryPreflight;
use Vtinnovations\GuardianTypo3\Application\Recovery\RestoreService;
use Vtinnovations\GuardianTypo3\Application\Recovery\VendorRecoveryService;
use Vtinnovations\GuardianTypo3\Infrastructure\Process\SymfonyProcessCommandExecutor;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\AtomicDirectorySwitch;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\RecoveryTransactionJournal;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\ComposerEnvironment;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\ComposerRuntime;
use Vtinnovations\GuardianTypo3\Domain\Archive\ArchiveEntryValidator;
use Vtinnovations\GuardianTypo3\Domain\Filesystem\PathNormalizer;
use Vtinnovations\GuardianTypo3\Infrastructure\Backup\BackupStorage;
use Vtinnovations\GuardianTypo3\Infrastructure\Backup\ComponentPathMap;
use Vtinnovations\GuardianTypo3\Infrastructure\Backup\FileCollector;
use Vtinnovations\GuardianTypo3\Infrastructure\Backup\ZipBackupArchiveWriter;
use Vtinnovations\GuardianTypo3\Infrastructure\Clock\SystemClock;
use Vtinnovations\GuardianTypo3\Infrastructure\Configuration\JsonRuntimeConfigurationRepository;
use Vtinnovations\GuardianTypo3\Infrastructure\Database\Typo3DatabaseDumper;
use Vtinnovations\GuardianTypo3\Infrastructure\Database\Typo3DatabaseImporter;
use Vtinnovations\GuardianTypo3\Infrastructure\Lock\FlockLockFactory;
use Vtinnovations\GuardianTypo3\Infrastructure\Maintenance\FileMaintenanceMode;
use Vtinnovations\GuardianTypo3\Infrastructure\Paths\GuardianPaths;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\JsonRecoveryHistoryStore;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\PanelRateLimiter;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\PanelTokenStore;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\RecoveryPanelConfigStore;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\ZipBackupArchiveExtractor;
use Vtinnovations\GuardianTypo3\Infrastructure\Composer\PathRepositoryInspector;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\ExtensionInformation;

/**
 * Hand-wires exactly the SAME Guardian recovery application services the backend
 * uses, but for the standalone panel — which runs WITHOUT booting TYPO3.
 *
 * There is deliberately NO second restore engine here: the kernel simply
 * constructs {@see RestoreService}, {@see RecoveryPreflight}, {@see BackupCatalog}
 * and their collaborators directly (they are framework-neutral value/POPO
 * services) and lets the shipped Guardian code do the actual work. The two
 * TYPO3-bound pieces — the database dumper/importer — read their connection
 * config from $GLOBALS['TYPO3_CONF_VARS'], so the kernel populates that global
 * from the project's config/system/settings.php before any recovery runs.
 *
 * Path discovery is location-based and safe: the panel file lives in the public
 * web root, whose parent is the project root; var/ sits under the project root.
 */
final class StandaloneRecoveryKernel
{
    private readonly string $projectPath;
    private readonly string $publicPath;

    private GuardianPaths $workingDirectory;
    private RecoveryPanelConfigStore $panelConfig;
    private PanelTokenStore $tokenStore;
    private PanelRateLimiter $rateLimiter;
    private BackupCatalog $catalog;
    private RecoveryPreflight $preflight;
    private RestoreService $restoreService;
    private RecoveryDryRun $dryRun;
    private RecoveryTransactionJournal $journal;

    /**
     * @param string $panelFile absolute path to the deployed panel entrypoint (__FILE__)
     */
    public function __construct(string $panelFile)
    {
        $this->publicPath = \dirname($panelFile);
        $this->projectPath = \dirname($this->publicPath);

        $this->loadTypo3Settings();
        $this->build();
    }

    public function panelConfig(): RecoveryPanelConfigStore
    {
        return $this->panelConfig;
    }

    public function tokenStore(): PanelTokenStore
    {
        return $this->tokenStore;
    }

    public function rateLimiter(): PanelRateLimiter
    {
        return $this->rateLimiter;
    }

    public function catalog(): BackupCatalog
    {
        return $this->catalog;
    }

    public function preflight(): RecoveryPreflight
    {
        return $this->preflight;
    }

    public function restoreService(): RestoreService
    {
        return $this->restoreService;
    }

    public function dryRun(): RecoveryDryRun
    {
        return $this->dryRun;
    }

    public function journal(): RecoveryTransactionJournal
    {
        return $this->journal;
    }

    public function projectPath(): string
    {
        return $this->projectPath;
    }

    private function build(): void
    {
        $pathNormalizer = new PathNormalizer();
        $environment = new StandaloneProjectEnvironment($this->projectPath, $this->publicPath);
        $this->workingDirectory = new GuardianPaths($environment, $pathNormalizer);

        $clock = new SystemClock();
        $lockFactory = new FlockLockFactory($this->workingDirectory);
        $maintenance = new FileMaintenanceMode($this->workingDirectory);
        $storage = new BackupStorage($this->workingDirectory, $pathNormalizer);
        $entryValidator = new ArchiveEntryValidator();
        $extractor = new ZipBackupArchiveExtractor($entryValidator);
        $componentPaths = new ComponentPathMap($environment, $pathNormalizer);
        $logger = new StandaloneFileLogger($this->workingDirectory);
        $history = new JsonRecoveryHistoryStore($this->workingDirectory);

        $databaseDumper = new Typo3DatabaseDumper();
        $databaseImporter = new Typo3DatabaseImporter();

        $this->catalog = new BackupCatalog($storage);

        // Full backup stack — reused for the mandatory pre-recovery safety snapshot.
        $backupService = new BackupService(
            $storage,
            new ZipBackupArchiveWriter($entryValidator),
            new FileCollector($environment, $storage, $entryValidator, $pathNormalizer),
            $databaseDumper,
            $lockFactory,
            $clock,
            $environment,
            new ExtensionInformation(),
            new RetentionPolicy(),
            $logger,
            new PathRepositoryInspector($pathNormalizer),
        );

        $this->panelConfig = new RecoveryPanelConfigStore($this->workingDirectory);
        $this->tokenStore = new PanelTokenStore($this->workingDirectory);
        $this->rateLimiter = new PanelRateLimiter($this->workingDirectory);

        $runtimeConfig = new RuntimeConfigurationService(
            new JsonRuntimeConfigurationRepository($this->workingDirectory)
        );
        $emailNotifier = new RecoveryEmailNotifier(new NullMailer(), $runtimeConfig, $this->panelConfig, $this->tokenStore);

        // Hardened vendor recovery + transaction journal shared with the backend.
        $composerEnvironment = new ComposerEnvironment($environment, $runtimeConfig);
        $executor = new SymfonyProcessCommandExecutor(new ComposerRuntime($this->workingDirectory));
        $vendorRecovery = new VendorRecoveryService($composerEnvironment, $executor, new AtomicDirectorySwitch(), $environment, $pathNormalizer);
        $this->journal = new RecoveryTransactionJournal($this->workingDirectory);

        $this->restoreService = new RestoreService(
            $this->catalog,
            $extractor,
            $componentPaths,
            $databaseImporter,
            $backupService,
            $maintenance,
            $lockFactory,
            $clock,
            $logger,
            $history,
            $storage,
            $this->workingDirectory,
            $pathNormalizer,
            $emailNotifier,
            $vendorRecovery,
            $this->journal,
            $executor,
            $composerEnvironment,
        );

        $this->dryRun = new RecoveryDryRun(
            $this->catalog,
            $extractor,
            $vendorRecovery,
            $environment,
            $this->workingDirectory,
        );

        $this->preflight = new RecoveryPreflight(
            $this->catalog,
            $componentPaths,
            $environment,
            $databaseImporter,
            $maintenance,
        );
    }

    /**
     * Populates $GLOBALS['TYPO3_CONF_VARS'] from the project's settings so the
     * database dumper/importer can read the connection config without TYPO3.
     */
    private function loadTypo3Settings(): void
    {
        foreach (['config/system/settings.php', 'config/system/additional.php', 'typo3conf/LocalConfiguration.php'] as $relative) {
            $file = $this->projectPath . '/' . $relative;
            if (!is_file($file)) {
                continue;
            }
            $data = @include $file;
            if (\is_array($data)) {
                $GLOBALS['TYPO3_CONF_VARS'] = array_replace_recursive(
                    $GLOBALS['TYPO3_CONF_VARS'] ?? [],
                    $data
                );
            }
        }
    }
}
