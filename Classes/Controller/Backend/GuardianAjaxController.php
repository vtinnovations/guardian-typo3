<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use Vtinnovations\GuardianTypo3\Application\Analysis\PreUpdateAnalysis;
use Vtinnovations\GuardianTypo3\Application\Backup\BackupNotificationService;
use Vtinnovations\GuardianTypo3\Application\Backup\BackupService;
use Vtinnovations\GuardianTypo3\Application\Backup\ComponentSelection;
use Vtinnovations\GuardianTypo3\Application\Backup\ScheduledBackupRunner;
use Vtinnovations\GuardianTypo3\Application\Configuration\PhpBinaryInspector;
use Vtinnovations\GuardianTypo3\Application\Configuration\RuntimeConfigurationService;
use Vtinnovations\GuardianTypo3\Application\Recovery\RecoveryEmailNotifier;
use Vtinnovations\GuardianTypo3\Application\Contract\BackendAuthorizationInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\RecoveryHistoryStoreInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\ScheduleConfigStoreInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\SystemLoggerInterface;
use Vtinnovations\GuardianTypo3\Application\Configuration\ActivationService;
use Vtinnovations\GuardianTypo3\Application\Environment\EntitlementReader;
use Vtinnovations\GuardianTypo3\Application\Recovery\BackupCatalog;
use Vtinnovations\GuardianTypo3\Application\Recovery\RecoveryDryRun;
use Vtinnovations\GuardianTypo3\Application\Recovery\RecoveryPreflight;
use Vtinnovations\GuardianTypo3\Application\Recovery\RestoreService;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\RecoveryTransactionJournal;
use Vtinnovations\GuardianTypo3\Application\Extension\CustomExtensionInstallService;
use Vtinnovations\GuardianTypo3\Application\Extension\ExtensionStateService;
use Vtinnovations\GuardianTypo3\Application\SelfMaintenance\SelfMaintenanceService;
use Vtinnovations\GuardianTypo3\Application\Ter\TerSearchService;
use Vtinnovations\GuardianTypo3\Application\Update\PackageManager;
use Vtinnovations\GuardianTypo3\Application\Update\PackageUpdateChecker;
use Vtinnovations\GuardianTypo3\Application\Update\UpdateService;
use Vtinnovations\GuardianTypo3\Infrastructure\Upload\UploadStagingArea;
use Vtinnovations\GuardianTypo3\Infrastructure\Process\SymfonyProcessCommandExecutor;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\ComposerEnvironment;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\Typo3ConsoleCommands;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\Typo3ReleaseDiscovery;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\UpdateJobStore;
use Vtinnovations\GuardianTypo3\Domain\Backup\BackupComponent;
use Vtinnovations\GuardianTypo3\Domain\Backup\BackupType;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Infrastructure\Backup\BackupStorage;
use Vtinnovations\GuardianTypo3\Infrastructure\Backup\ZipBackupArchiveWriter;
use Vtinnovations\GuardianTypo3\Infrastructure\Packages\InstalledPackages;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\PanelTokenStore;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\RecoveryPanelConfigStore;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\RecoveryPanelDeployer;
use Vtinnovations\GuardianTypo3\Infrastructure\Recovery\RecoveryTokenReader;

/**
 * Backend AJAX endpoints backing the Guardian interface, including the complete
 * Backup slice (options, create, list, details, download, delete, schedule
 * get/save, run mini/full now, test e-mail).
 *
 * Every action asserts an authenticated TYPO3 backend administrator; the backend
 * AJAX routes additionally carry TYPO3's CSRF route token. License entitlement
 * is enforced server-side (manual backup needs any license; scheduled backups
 * need Pro) — never inferred from the browser. Responses are structured JSON
 * with no stack traces, no credentials and no raw shell output.
 */
final class GuardianAjaxController
{
    public function __construct(
        private readonly BackendAuthorizationInterface $authorization,
        private readonly EntitlementReader $entitlement,
        private readonly ActivationService $activation,
        private readonly InstalledPackages $installedPackages,
        private readonly RuntimeConfigurationService $runtimeConfiguration,
        private readonly RecoveryTokenReader $recoveryToken,
        private readonly PreUpdateAnalysis $analysis,
        private readonly BackupService $backupService,
        private readonly ScheduledBackupRunner $scheduledRunner,
        private readonly BackupStorage $backupStorage,
        private readonly ScheduleConfigStoreInterface $scheduleConfig,
        private readonly BackupNotificationService $notifier,
        private readonly UriBuilder $uriBuilder,
        private readonly RecoveryPanelConfigStore $panelConfig,
        private readonly PanelTokenStore $tokenStore,
        private readonly RecoveryPanelDeployer $deployer,
        private readonly BackupCatalog $recoveryCatalog,
        private readonly RecoveryPreflight $preflight,
        private readonly RestoreService $restoreService,
        private readonly RecoveryHistoryStoreInterface $recoveryHistory,
        private readonly UpdateService $updateService,
        private readonly PackageUpdateChecker $packageUpdateChecker,
        private readonly ComposerEnvironment $composerEnvironment,
        private readonly SymfonyProcessCommandExecutor $processExecutor,
        private readonly Typo3ConsoleCommands $consoleCommands,
        private readonly UpdateJobStore $updateJobStore,
        private readonly RecoveryDryRun $recoveryDryRun,
        private readonly RecoveryTransactionJournal $recoveryJournal,
        private readonly Typo3ReleaseDiscovery $releaseDiscovery,
        private readonly PackageManager $packageManager,
        private readonly TerSearchService $terSearch,
        private readonly UploadStagingArea $uploadStaging,
        private readonly CustomExtensionInstallService $customInstall,
        private readonly \Vtinnovations\GuardianTypo3\Application\Extension\ManagedPackageRemover $managedPackageRemover,
        private readonly ExtensionStateService $extensionState,
        private readonly SelfMaintenanceService $selfMaintenance,
        private readonly RecoveryEmailNotifier $recoveryEmailNotifier,
        private readonly PhpBinaryInspector $phpBinaryInspector,
        private readonly SystemLoggerInterface $systemLogger,
    ) {
    }

    // ── License ──────────────────────────────────────────────────────────────

    public function licenseStatus(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }

        return new JsonResponse(['success' => true, 'code' => 'ok'] + $this->entitlement->grant()->toPublicArray());
    }

    public function licenseActivate(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        if ($payload === null) {
            return new JsonResponse(['success' => false, 'code' => 'invalid_payload', 'error' => 'Invalid JSON payload.'], 400);
        }

        // The host is never taken from the submitted payload: it is resolved
        // from trusted request data inside the activation flow.
        try {
            $grant = $this->activation->activate((string) ($payload['key'] ?? ''));
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'activation_failed', 'error' => $e->getMessage()], 500);
        }

        return new JsonResponse(['success' => true, 'code' => 'ok', 'valid' => $grant->isLicensed()] + $grant->toPublicArray());
    }

    /**
     * Re-confirms the record already held, announcing the version in effect. No
     * key is submitted: the stored one is used server-side, so the full key
     * neither leaves nor re-enters the browser.
     */
    public function licenseRefresh(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }

        try {
            $grant = $this->activation->refresh();
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'refresh_failed', 'error' => $e->getMessage()], 500);
        }

        return new JsonResponse(['success' => true, 'code' => 'ok', 'valid' => $grant->isLicensed()] + $grant->toPublicArray());
    }

    public function licenseClear(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }

        try {
            $grant = $this->activation->withdraw();
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'removal_failed', 'error' => $e->getMessage()], 500);
        }

        return new JsonResponse(['success' => true, 'code' => 'ok'] + $grant->toPublicArray());
    }

    // ── Packages / analysis / misc reads ────────────────────────────────────

    public function packages(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }

        $packages = $this->installedPackages->listInstalled();
        $abandoned = 0;
        foreach ($packages as $pkg) {
            if ($pkg['abandoned']) {
                $abandoned++;
            }
        }

        return new JsonResponse([
            'success' => true,
            'packages' => $packages,
            'stats' => ['total' => \count($packages), 'updates' => 0, 'abandoned' => $abandoned, 'cached' => false, 'error' => null],
        ]);
    }

    public function runtimeGet(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }

        return new JsonResponse(['success' => true, 'config' => $this->runtimeConfiguration->current()->toArray()]);
    }

    // ── Settings: recovery e-mail notifications (admin) ───────────────────────

    /**
     * Persist the recovery-notification settings. Recipients are validated +
     * normalised by the value object; if the operator entered something but no
     * valid address survived, that is reported as `no_valid_recipient`.
     */
    public function notificationsSave(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        if ($payload === null) {
            return new JsonResponse(['success' => false, 'code' => 'invalid_payload', 'reason' => 'invalid_payload'], 400);
        }
        $enabled = ($payload['enabled'] ?? false) === true || ($payload['enabled'] ?? null) === '1';
        $recipientsInput = trim((string) ($payload['recipients'] ?? ''));
        $sender = trim((string) ($payload['sender'] ?? ''));

        $config = $this->runtimeConfiguration->saveNotifications($enabled, $recipientsInput, $sender);
        // Distinguish "typed something invalid" from "intentionally empty".
        if ($recipientsInput !== '' && $config->recoveryEmail === '') {
            return new JsonResponse(['success' => false, 'code' => 'no_valid_recipient', 'reason' => 'no_valid_recipient', 'config' => $config->toArray()], 422);
        }

        return new JsonResponse(['success' => true, 'code' => 'ok', 'config' => $config->toArray()]);
    }

    public function notificationsTest(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        // Persist first (like the backup test) so the test uses exactly what is
        // shown, then send.
        $payload = $this->jsonBody($request);
        if (\is_array($payload)) {
            $enabled = ($payload['enabled'] ?? false) === true || ($payload['enabled'] ?? null) === '1';
            $this->runtimeConfiguration->saveNotifications($enabled, trim((string) ($payload['recipients'] ?? '')), trim((string) ($payload['sender'] ?? '')));
        }

        try {
            $recipient = $this->recoveryEmailNotifier->sendTest();
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'mail_failed', 'reason' => $e->getMessage()], 502);
        }

        return new JsonResponse(['success' => true, 'code' => 'ok', 'recipient' => $recipient]);
    }

    // ── Settings: PHP CLI binary (admin) ──────────────────────────────────────

    public function phpDetect(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }

        @set_time_limit(0);

        return new JsonResponse(['success' => true] + $this->phpBinaryInspector->detect());
    }

    public function phpTest(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        $path = \is_array($payload) ? (string) ($payload['path'] ?? '') : '';
        if ($path === '') {
            return new JsonResponse(['success' => false, 'code' => 'invalid_payload', 'reason' => 'not_absolute'], 400);
        }

        return new JsonResponse(['success' => true, 'result' => $this->phpBinaryInspector->test($path)]);
    }

    public function phpSave(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        $path = \is_array($payload) ? trim((string) ($payload['path'] ?? '')) : '';

        // An empty path clears the override (falls back to auto-detection).
        if ($path !== '') {
            $result = $this->phpBinaryInspector->test($path);
            if ($result['valid'] !== true) {
                return new JsonResponse(['success' => false, 'code' => 'invalid_php_binary', 'reason' => $result['error_code'] ?? 'not_a_php_binary', 'result' => $result], 422);
            }
        }

        $config = $this->runtimeConfiguration->savePhpBinary($path);

        return new JsonResponse(['success' => true, 'code' => 'ok', 'config' => $config->toArray()]);
    }

    public function panelGet(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }

        return new JsonResponse([
            'success' => true,
            'source' => $this->recoveryToken->source(),
            'token' => null,
            'filename' => $this->runtimeConfiguration->current()->recoveryPanelFilename,
        ]);
    }

    public function analyse(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }

        return new JsonResponse(['success' => true, 'result' => $this->analysis->run()]);
    }

    public function jobStatus(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }

        return new JsonResponse(['success' => true, 'job' => $this->updateService->status()]);
    }

    public function jobArchive(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }

        return new JsonResponse(['success' => true, 'jobs' => $this->updateService->recent(20)]);
    }

    // ── Update (admin + Pro) ──────────────────────────────────────────────────

    public function updatePackages(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        return new JsonResponse(['success' => true, 'packages' => $this->installedPackages->listInstalled()]);
    }

    public function updateCheck(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        @set_time_limit(0);

        // TYPO3 release discovery via direct HTTPS metadata (no Composer CLI):
        // this is the authoritative source for the installed version and the
        // available upgrade paths, and works even where the Composer subprocess
        // cannot run.
        $release = $this->releaseDiscovery->discover();
        if ($release['success'] !== true) {
            return new JsonResponse([
                'success' => false,
                'status' => 'transport_error',
                'errorCode' => $release['errorCode'] ?? 'update_metadata_unreachable',
                'message' => 'Unable to retrieve TYPO3 release metadata.',
                'installedVersion' => $release['installedVersion'],
                'diagnostics' => $release['diagnostics'],
            ], 502);
        }

        $installed = (string) $release['installedVersion'];
        $upgradePaths = [
            'installedVersion' => $installed,
            'currentMajor' => $release['currentMajor'],
            'latestCurrentMajor' => $release['latestCurrentMajor'],
            'nextMajor' => $release['nextMajor'],
            'status' => 'success',
            'errors' => [],
        ];

        // The installed-package list (for selective updates) still comes from
        // Composer, but its failure is NON-FATAL: it must never hide the release
        // metadata. On failure the list is simply empty.
        $packages = [];
        try {
            $packageResult = $this->packageUpdateChecker->check();
            if (($packageResult['error'] ?? null) === null) {
                $packages = $packageResult['packages'];
            }
        } catch (\Throwable) {
            $packages = [];
        }

        return new JsonResponse([
            'success' => true,
            'installedVersion' => $installed,
            'source' => $release['source'],
            'upgradePaths' => $upgradePaths,
            'upgradePathList' => $release['upgradePaths'],
            'packages' => $packages,
            'checked_at' => gmdate('c'),
        ]);
    }

    public function updateDryRun(ServerRequestInterface $request): ResponseInterface
    {
        return $this->startUpdateJob($request, true);
    }

    public function updateStart(ServerRequestInterface $request): ResponseInterface
    {
        return $this->startUpdateJob($request, false);
    }

    public function updateJobStatus(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        return new JsonResponse(['success' => true, 'job' => $this->updateService->status()]);
    }

    public function updateJobLog(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $offset = (int) ($request->getQueryParams()['offset'] ?? 0);

        return new JsonResponse(['success' => true] + $this->updateService->readLog($offset));
    }

    public function updateJobs(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        return new JsonResponse(['success' => true, 'jobs' => $this->updateService->recent(20)]);
    }

    public function updateJobDetails(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $id = (string) ($request->getQueryParams()['id'] ?? '');
        $details = $this->updateService->details($id);
        if ($details === null) {
            return new JsonResponse(['success' => false, 'code' => 'not_found', 'error' => 'Update job not found.'], 404);
        }

        return new JsonResponse(['success' => true, 'job' => $details]);
    }

    public function updateRollback(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        $jobId = \is_array($payload) ? (string) ($payload['jobId'] ?? '') : '';
        $job = $this->updateJobStore->getArchived($jobId);
        if ($job === null) {
            return new JsonResponse(['success' => false, 'code' => 'not_found', 'error' => 'Update job not found.'], 404);
        }
        $snapshot = (string) ($job->options['safety_backup'] ?? '');
        if ($snapshot === '') {
            return new JsonResponse(['success' => false, 'code' => 'no_snapshot', 'error' => 'That job has no safety backup to roll back to.'], 409);
        }

        // Reuse the shared Recovery engine — no duplicate rollback logic.
        $components = [];
        foreach (BackupComponent::cases() as $component) {
            $components[$component->value] = true;
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        try {
            $result = $this->restoreService->restore($snapshot, $components, true, true, 'rebuild');
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'rollback_failed', 'error' => $e->getMessage()], 500);
        }

        return new JsonResponse([
            'success' => true,
            'code' => 'ok',
            'restored' => $result->restoredComponents,
            'rolledBack' => $result->rolledBack,
            'log' => $result->log,
        ]);
    }

    public function updateConfigTest(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $php = $this->composerEnvironment->phpBinary();
        $composer = $this->composerEnvironment->composerBinary();
        $console = $this->composerEnvironment->typo3Console();

        $report = [
            'php_binary' => $php,
            'composer_binary' => $composer,
            'console_binary' => $console,
            'exec_available' => $this->processExecutor->isAvailable(),
            'php_version' => null,
            'composer_version' => null,
        ];

        if ($php !== null && $composer !== null && $this->processExecutor->isAvailable()) {
            $factory = new \Vtinnovations\GuardianTypo3\Application\Update\ComposerCommandFactory($php, $composer, \dirname($composer));
            $version = $this->processExecutor->run($factory->validate());
            $report['composer_version'] = $version->isSuccessful() ? trim($version->stdout) : null;
        }

        return new JsonResponse(['success' => true, 'config' => $report]);
    }

    private function startUpdateJob(ServerRequestInterface $request, bool $dryRun): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        if ($payload === null) {
            return new JsonResponse(['success' => false, 'code' => 'invalid_payload', 'error' => 'Invalid JSON payload.'], 400);
        }
        $mode = (string) ($payload['mode'] ?? 'full');
        $packages = \is_array($payload['packages'] ?? null) ? array_values(array_filter(array_map('strval', $payload['packages']))) : [];

        if (!$dryRun && ($payload['confirm'] ?? false) !== true) {
            return new JsonResponse(['success' => false, 'code' => 'not_confirmed', 'error' => 'The update must be explicitly confirmed.'], 400);
        }

        try {
            $job = $dryRun
                ? $this->updateService->startDryRun($mode, $packages)
                : $this->updateService->startUpdate($mode, $packages, ($payload['snapshotVendor'] ?? true) !== false, $this->authorization->currentUserIdentifier() ?? 'admin');
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'start_failed', 'error' => $e->getMessage()], 409);
        }

        return new JsonResponse(['success' => true, 'code' => 'ok', 'job' => $this->updateService->status(), 'jobId' => $job->id]);
    }

    // ── Dashboard package management (admin; mutations need Pro) ───────────────

    /**
     * Enriched installed-package list for the "Manage installed packages" panel:
     * classification, extension key, source, composer-managed + active state and
     * the SERVER-SIDE decision (with a precise reason) about whether each of
     * Update / Disable / Enable / Remove may be performed.
     */
    public function dashboardPackages(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }
        // The Extensions section — including its read-only list — is Pro-only.
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        // Real, per-package update metadata (composer outdated, respecting the
        // current TYPO3/PHP + constraint set). A failure is classified and passed
        // to the model so rows read "Update check failed" — never a fake
        // "Up to date". Metadata is optional; the query param skips it.
        $updateMap = [];
        $updateError = null;
        if (($request->getQueryParams()['metadata'] ?? '1') !== '0') {
            @set_time_limit(0);
            [$updateMap, $updateError] = $this->buildUpdateMap();
        } else {
            // No metadata requested → the model reports "metadata unavailable".
            $updateMap = [];
            $updateError = null;
        }

        $model = $this->packageManager->list($updateMap, $updateError);
        $pro = $this->entitlement->isPro();

        return new JsonResponse([
            'success' => true,
            'operationInProgress' => $model['operationInProgress'],
            'updateMetadata' => $model['updateMetadata'],
            'mutationsAllowed' => $pro,
            'packages' => $model['packages'],
        ]);
    }

    /**
     * @return array{0: array<string, array{latest: string, latest_overall: string, has_update: bool, update_state: string}>, 1: ?string}
     */
    private function buildUpdateMap(): array
    {
        try {
            $check = $this->packageUpdateChecker->check();
        } catch (\Throwable) {
            return [[], 'update_check_failed'];
        }
        if (($check['error_code'] ?? null) !== null) {
            return [[], 'update_check_failed'];
        }

        $map = [];
        foreach ($check['packages'] as $pkg) {
            $name = (string) ($pkg['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $status = (string) ($pkg['status'] ?? 'unknown');
            $map[$name] = [
                'latest' => (string) ($pkg['latest'] ?? ''),
                'latest_overall' => (string) ($pkg['latest'] ?? ''),
                'has_update' => ($pkg['has_update'] ?? false) === true,
                'update_state' => $this->mapUpdateState($status),
            ];
        }

        return [$map, null];
    }

    private function mapUpdateState(string $status): string
    {
        return match ($status) {
            'patch_available', 'minor_available' => 'update_available',
            'major_available' => 'major_update_available',
            'current', 'abandoned' => 'up_to_date',
            'error' => 'update_check_failed',
            default => 'metadata_unavailable',
        };
    }

    public function packageUpdateDryRun(ServerRequestInterface $request): ResponseInterface
    {
        return $this->runPackageJob($request, 'update', true);
    }

    public function packageUpdateStart(ServerRequestInterface $request): ResponseInterface
    {
        return $this->runPackageJob($request, 'update', false);
    }

    public function packageRemoveDryRun(ServerRequestInterface $request): ResponseInterface
    {
        return $this->runPackageJob($request, 'remove', true);
    }

    public function packageRemoveStart(ServerRequestInterface $request): ResponseInterface
    {
        return $this->runPackageJob($request, 'remove', false);
    }

    public function packageDisable(ServerRequestInterface $request): ResponseInterface
    {
        return $this->runPackageStateChange($request, 'disable');
    }

    public function packageEnable(ServerRequestInterface $request): ResponseInterface
    {
        return $this->runPackageStateChange($request, 'enable');
    }

    /**
     * Shared handler for the Update and Remove actions. Both validate the request
     * SERVER-SIDE via {@see PackageManager} (rejecting core, Guardian itself,
     * transitive/required dependencies, etc.) and then run through the identical
     * dry-run → backup → maintenance → composer → schema → cache → verify →
     * rollback pipeline that powers the update feature.
     */
    private function runPackageJob(ServerRequestInterface $request, string $action, bool $dryRun): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        $name = \is_array($payload) ? (string) ($payload['package'] ?? '') : '';
        if ($name === '') {
            return new JsonResponse(['success' => false, 'code' => 'invalid_payload', 'error' => 'A package name is required.'], 400);
        }
        if (!$dryRun && ($payload['confirm'] ?? false) !== true) {
            return new JsonResponse(['success' => false, 'code' => 'not_confirmed', 'error' => 'The action must be explicitly confirmed.'], 400);
        }

        try {
            if ($action === 'remove') {
                $this->packageManager->assertRemovable($name);
            } else {
                $this->packageManager->assertUpdatable($name);
            }
        } catch (GuardianException $e) {
            // Machine reason code (e.g. core_cannot_remove, required_by_other).
            return new JsonResponse(['success' => false, 'code' => 'action_not_permitted', 'reason' => $e->getMessage()], 409);
        }

        $snapshotVendor = \is_array($payload) ? (($payload['snapshotVendor'] ?? true) !== false) : true;
        $admin = $this->authorization->currentUserIdentifier() ?? 'admin';

        // For a Guardian-managed uploaded package, resolve the ownership-verified
        // removal plan. delete_source is honoured ONLY when ownership is proven,
        // regardless of what the client requested.
        $removalPlan = $action === 'remove' ? $this->managedPackageRemover->plan($name) : null;
        $requestedDeleteSource = \is_array($payload) && ($payload['deleteSource'] ?? false) === true;
        $deleteSource = $removalPlan !== null && ($removalPlan['ownership_verified'] ?? false) === true && $requestedDeleteSource;

        try {
            if ($action === 'remove') {
                $job = $dryRun
                    ? $this->updateService->startRemoveDryRun([$name])
                    : $this->updateService->startRemove([$name], $snapshotVendor, $admin, $deleteSource);
            } else {
                $job = $dryRun
                    ? $this->updateService->startDryRun('selective', [$name])
                    : $this->updateService->startUpdate('selective', [$name], $snapshotVendor, $admin);
            }
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'start_failed', 'error' => $e->getMessage()], 409);
        }

        $response = ['success' => true, 'code' => 'ok', 'job' => $this->updateService->status(), 'jobId' => $job->id];
        if ($removalPlan !== null) {
            $response['managedRemoval'] = $this->publicRemovalPlan($removalPlan);
        }

        return new JsonResponse($response);
    }

    /**
     * Browser-safe view of a managed-removal plan: the absolute path stays
     * server-side; only the project-relative source path is exposed.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function publicRemovalPlan(array $plan): array
    {
        return [
            'managed' => (bool) ($plan['managed'] ?? false),
            'ownership_verified' => (bool) ($plan['ownership_verified'] ?? false),
            'reason' => $plan['reason'] ?? null,
            'package' => (string) ($plan['package'] ?? ''),
            'extension_key' => (string) ($plan['extension_key'] ?? ''),
            'version' => (string) ($plan['version'] ?? ''),
            'source_relative' => (string) ($plan['source_relative'] ?? ''),
            'detected_name' => $plan['detected_name'] ?? null,
            'detected_version' => $plan['detected_version'] ?? null,
        ];
    }

    /**
     * Disable / Enable a real TYPO3 extension through TYPO3's supported package
     * API (never a manual package-state edit). Eligibility is enforced by
     * {@see PackageManager} and the actual, reversible state change by
     * {@see ExtensionStateService}. Requires POST + admin + Pro + CSRF + explicit
     * confirmation, and is blocked while a Guardian operation is running.
     */
    private function runPackageStateChange(ServerRequestInterface $request, string $action): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        $name = \is_array($payload) ? (string) ($payload['package'] ?? '') : '';
        if ($name === '') {
            return new JsonResponse(['success' => false, 'code' => 'invalid_payload', 'error' => 'A package name is required.'], 400);
        }
        if (($payload['confirm'] ?? false) !== true) {
            return new JsonResponse(['success' => false, 'code' => 'not_confirmed', 'error' => 'The action must be explicitly confirmed.'], 400);
        }

        try {
            $result = $action === 'disable'
                ? $this->extensionState->disable($name)
                : $this->extensionState->enable($name);
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'action_not_permitted', 'reason' => $e->getMessage()], 409);
        }

        return new JsonResponse(['success' => true, 'code' => 'ok'] + $result);
    }

    // ── Extensions: TER search + install (admin + Pro) ────────────────────────

    public function terSearch(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        $query = \is_array($payload) ? (string) ($payload['query'] ?? '') : '';
        try {
            $result = $this->terSearch->search($query);
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'ter_error', 'reason' => $e->getMessage()], 502);
        }

        return new JsonResponse(['success' => true] + $result);
    }

    public function terAnalyse(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        $key = \is_array($payload) ? (string) ($payload['extensionKey'] ?? '') : '';
        try {
            $analysis = $this->terSearch->analyse($key);
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'ter_error', 'reason' => $e->getMessage()], 502);
        }

        return new JsonResponse(['success' => true, 'extension' => $analysis]);
    }

    public function terInstallDryRun(ServerRequestInterface $request): ResponseInterface
    {
        return $this->startTerInstall($request, true);
    }

    public function terInstallStart(ServerRequestInterface $request): ResponseInterface
    {
        return $this->startTerInstall($request, false);
    }

    /**
     * Resolve the TER extension's Composer identity SERVER-SIDE (the browser only
     * sends an extension key), reject when automatic install is unavailable, then
     * drive the shared dry-run / install pipeline.
     */
    private function startTerInstall(ServerRequestInterface $request, bool $dryRun): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        $key = \is_array($payload) ? (string) ($payload['extensionKey'] ?? '') : '';
        if (!$dryRun && ($payload['confirm'] ?? false) !== true) {
            return new JsonResponse(['success' => false, 'code' => 'not_confirmed', 'error' => 'The installation must be explicitly confirmed.'], 400);
        }

        try {
            $analysis = $this->terSearch->analyse($key);
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'ter_error', 'reason' => $e->getMessage()], 502);
        }
        if (($analysis['auto_installable'] ?? false) !== true || !\is_string($analysis['composer_name'] ?? null)) {
            return new JsonResponse(['success' => false, 'code' => 'not_installable', 'reason' => $analysis['reason'] ?? 'composer_identity_missing'], 409);
        }

        $admin = $this->authorization->currentUserIdentifier() ?? 'admin';
        $snapshotVendor = \is_array($payload) ? (($payload['snapshotVendor'] ?? true) !== false) : true;
        try {
            $job = $dryRun
                ? $this->updateService->startTerInstallDryRun($analysis['composer_name'])
                : $this->updateService->startTerInstall($analysis['composer_name'], $snapshotVendor, $admin);
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'start_failed', 'error' => $e->getMessage()], 409);
        }

        return new JsonResponse(['success' => true, 'code' => 'ok', 'job' => $this->updateService->status(), 'jobId' => $job->id]);
    }

    // ── Extensions: custom ZIP upload (admin + Pro) ───────────────────────────

    public function uploadExtension(ServerRequestInterface $request): ResponseInterface
    {
        // Protected proof the controller was actually reached, with the PSR-7
        // uploaded-file field names present (never the file contents).
        $files = $request->getUploadedFiles();
        $this->systemLogger->info(
            sprintf('Extension upload endpoint reached (uploaded fields: %s).', $files === [] ? 'none' : implode(', ', array_keys($files))),
            'extensions'
        );

        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        // PSR-7 uploaded-file retrieval — identical on TYPO3 13.4 and 14. Accept
        // the primary field and a couple of tolerant aliases, and flatten a
        // possible nested array shape from some clients.
        $uploaded = $files['extensionArchive'] ?? ($files['extension'] ?? ($files['file'] ?? null));
        if (\is_array($uploaded)) {
            $uploaded = reset($uploaded) ?: null;
        }
        if (!$uploaded instanceof \Psr\Http\Message\UploadedFileInterface) {
            // Distinguish "no field at all" from PHP dropping an over-sized body
            // (post_max_size) — both leave getUploadedFiles() empty but the second
            // has a Content-Length larger than the configured limit.
            $reason = $this->uploadWasTruncated($request) ? 'upload_too_large' : 'no_file_field';
            $this->systemLogger->warning('Extension upload had no usable file field (' . $reason . ').', 'extensions');

            return new JsonResponse(['success' => false, 'code' => 'upload_missing', 'reason' => $reason], 400);
        }
        if ($uploaded->getError() !== \UPLOAD_ERR_OK) {
            return new JsonResponse(['success' => false, 'code' => 'upload_incomplete', 'reason' => $this->uploadErrorReason($uploaded->getError())], 400);
        }
        if ((int) $uploaded->getSize() > $this->uploadStaging->maxUploadBytes()) {
            return new JsonResponse(['success' => false, 'code' => 'upload_too_large', 'reason' => 'upload_too_large'], 413);
        }

        // Stage the PSR-7 file DIRECTLY into var/guardian/extensions/uploads/<id>/
        // — never the system temp dir (which move_uploaded_file() cannot reach
        // under open_basedir/Plesk, the reported RuntimeException).
        try {
            $staged = $this->uploadStaging->acceptUploadedFile($uploaded);
        } catch (GuardianException $e) {
            // $e->getMessage() is already a language-neutral machine reason code.
            $this->systemLogger->warning('Extension upload failed: ' . $e->getMessage(), 'extensions');

            return new JsonResponse($this->uploadFailureBody($e->getMessage()), 422);
        } catch (\Throwable $e) {
            // Log the REAL cause (class + message) server-side; return a precise,
            // safe code + the exception's short class — never the raw message, a
            // path or a stack trace.
            $this->systemLogger->error(sprintf('Extension upload processing failed [%s]: %s', $e::class, $e->getMessage()), 'extensions');

            return new JsonResponse($this->uploadFailureBody('upload_processing_error') + ['detail' => $this->shortClassName($e)], 500);
        }

        return new JsonResponse(['success' => true, 'token' => $staged['token'], 'checksum' => $staged['checksum'], 'filename' => $staged['filename']]);
    }

    /**
     * The structured, safe upload-failure result: a language-neutral error code,
     * plus the private (relative) runtime area for path-related failures. The UI
     * localises the summary / details / recommendation from the error code.
     *
     * @return array{success: bool, code: string, errorCode: string, reason: string, area: ?string}
     */
    private function uploadFailureBody(string $errorCode): array
    {
        $pathRelated = \in_array($errorCode, [
            'upload_root_creation_failed', 'upload_root_not_writable', 'upload_directory_creation_failed',
            'upload_destination_invalid', 'upload_move_failed', 'upload_stream_copy_failed',
            'upload_size_mismatch', 'upload_disk_space_insufficient', 'upload_store_failed',
        ], true);

        return [
            'success' => false,
            'code' => 'upload_failed',
            'errorCode' => $errorCode,
            'reason' => $errorCode, // backward-compatible with the generic error renderer
            'area' => $pathRelated ? $this->uploadStaging->runtimeArea() : null,
        ];
    }

    /**
     * Map a PHP UPLOAD_ERR_* code to a safe machine reason.
     */
    private function uploadErrorReason(int $error): string
    {
        return match ($error) {
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => 'upload_too_large',
            \UPLOAD_ERR_PARTIAL, \UPLOAD_ERR_NO_FILE => 'upload_incomplete',
            \UPLOAD_ERR_NO_TMP_DIR, \UPLOAD_ERR_CANT_WRITE => 'upload_store_failed',
            default => 'upload_incomplete',
        };
    }

    /**
     * Best-effort detection of a body that PHP discarded for exceeding
     * post_max_size (Content-Length present but $_POST/files empty).
     */
    private function uploadWasTruncated(ServerRequestInterface $request): bool
    {
        $length = (int) ($request->getHeaderLine('Content-Length') ?: 0);
        if ($length <= 0) {
            return false;
        }
        $postMax = $this->iniBytes((string) \ini_get('post_max_size'));

        return $postMax > 0 && $length > $postMax;
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower($value[\strlen($value) - 1]);
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function shortClassName(\Throwable $e): string
    {
        $parts = explode('\\', $e::class);

        return (string) end($parts);
    }

    public function uploadInspect(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        $token = \is_array($payload) ? (string) ($payload['token'] ?? '') : '';
        try {
            $described = $this->customInstall->describe($token);
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'inspect_failed', 'reason' => $e->getMessage()], 422);
        }

        return new JsonResponse([
            'success' => true,
            'token' => $described['token'],
            'checksum' => $described['checksum'],
            'fingerprint' => $described['fingerprint'],
            'inspection' => $described['inspection'],
            'installable' => $described['local_package'] !== null,
            'existingDirectory' => $described['existing_directory'],
        ]);
    }

    /**
     * Remove a verified Guardian-owned ORPHAN directory so the same extension can
     * be re-uploaded. Only deletes when ownership is proven.
     */
    public function customOrphanRemove(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        $key = \is_array($payload) ? (string) ($payload['extensionKey'] ?? '') : '';
        if ($key === '') {
            return new JsonResponse(['success' => false, 'code' => 'invalid_payload', 'error' => 'An extension key is required.'], 400);
        }
        try {
            $result = $this->customInstall->removeOrphanedDirectory($key);
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'orphan_remove_failed', 'reason' => $e->getMessage()], 409);
        }

        return new JsonResponse(['success' => true, 'code' => 'ok'] + $result);
    }

    public function customDryRun(ServerRequestInterface $request): ResponseInterface
    {
        return $this->startCustomInstall($request, true);
    }

    public function customInstallStart(ServerRequestInterface $request): ResponseInterface
    {
        return $this->startCustomInstall($request, false);
    }

    private function startCustomInstall(ServerRequestInterface $request, bool $dryRun): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        $token = \is_array($payload) ? (string) ($payload['token'] ?? '') : '';
        $fingerprint = \is_array($payload) ? (string) ($payload['fingerprint'] ?? '') : '';
        if ($token === '' || $fingerprint === '') {
            return new JsonResponse(['success' => false, 'code' => 'invalid_payload', 'error' => 'A staged token and fingerprint are required.'], 400);
        }
        if (!$dryRun && ($payload['confirm'] ?? false) !== true) {
            return new JsonResponse(['success' => false, 'code' => 'not_confirmed', 'error' => 'The installation must be explicitly confirmed.'], 400);
        }

        $admin = $this->authorization->currentUserIdentifier() ?? 'admin';
        $snapshotVendor = \is_array($payload) ? (($payload['snapshotVendor'] ?? true) !== false) : true;
        try {
            $job = $dryRun
                ? $this->customInstall->startDryRun($token, $fingerprint)
                : $this->customInstall->startInstall($token, $fingerprint, $snapshotVendor, $admin);
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'start_failed', 'reason' => $e->getMessage()], 409);
        }

        return new JsonResponse(['success' => true, 'code' => 'ok', 'job' => $this->updateService->status(), 'jobId' => $job->id]);
    }

    public function uploadCleanup(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        $token = \is_array($payload) ? (string) ($payload['token'] ?? '') : '';
        try {
            $this->uploadStaging->cleanup($token);
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'cleanup_failed', 'reason' => $e->getMessage()], 422);
        }

        return new JsonResponse(['success' => true]);
    }

    // ── Guardian self-maintenance (admin + system maintainer + Pro) ───────────

    /**
     * Queue the DEFERRED Guardian self-disable. Guardian cannot deactivate itself
     * inside this request, so this only records a fixed job + spawns a detached
     * worker; the response returns first, then the worker deactivates Guardian via
     * the supported TYPO3 API. Requires a system maintainer and the exact typed
     * phrase "DISABLE GUARDIAN".
     */
    public function guardianSelfDisable(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardSelfMaintenance($request)) !== null) {
            return $deny;
        }
        $payload = $this->jsonBody($request);
        $phrase = \is_array($payload) ? (string) ($payload['confirmPhrase'] ?? '') : '';
        if (trim($phrase) !== 'DISABLE GUARDIAN') {
            return new JsonResponse(['success' => false, 'code' => 'not_confirmed', 'reason' => 'confirm_phrase_mismatch'], 400);
        }

        try {
            $result = $this->selfMaintenance->requestDisable($this->authorization->currentUserIdentifier() ?? 'admin');
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'self_disable_failed', 'reason' => $e->getMessage()], 409);
        }

        return new JsonResponse(['success' => true, 'code' => 'ok', 'jobId' => $result['jobId'], 'deferred' => true, 'redirect' => $this->safeBackendRedirect()]);
    }

    public function guardianSelfStatus(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        return new JsonResponse(['success' => true, 'status' => $this->selfMaintenance->status()]);
    }

    public function guardianUninstallDryRun(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardSelfMaintenance($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->assertGuardianIsRootRequirement()) !== null) {
            return $deny;
        }

        $this->selfMaintenance->assertGuardianIdentity(SelfMaintenanceService::GUARDIAN_PACKAGE);
        try {
            $job = $this->updateService->startRemoveDryRun([SelfMaintenanceService::GUARDIAN_PACKAGE]);
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'start_failed', 'reason' => $e->getMessage()], 409);
        }

        return new JsonResponse(['success' => true, 'code' => 'ok', 'job' => $this->updateService->status(), 'jobId' => $job->id]);
    }

    /**
     * Controlled deferred Guardian removal. Reuses the existing update-remove job
     * pipeline (lock, mandatory backup, Composer runner with the safe HOME/
     * COMPOSER_HOME env, argv arrays, extension setup, cache, verify, rollback);
     * the detached worker runs `composer remove vtinnovations/guardian-typo3`.
     * Never deletes packages/guardian-typo3 (composer remove only unregisters).
     */
    public function guardianUninstall(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardSelfMaintenance($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->assertGuardianIsRootRequirement()) !== null) {
            return $deny;
        }
        $payload = $this->jsonBody($request);
        $phrase = \is_array($payload) ? (string) ($payload['confirmPhrase'] ?? '') : '';
        if (trim($phrase) !== 'REMOVE GUARDIAN') {
            return new JsonResponse(['success' => false, 'code' => 'not_confirmed', 'reason' => 'confirm_phrase_mismatch'], 400);
        }

        $this->selfMaintenance->assertGuardianIdentity(SelfMaintenanceService::GUARDIAN_PACKAGE);
        $admin = $this->authorization->currentUserIdentifier() ?? 'admin';
        $snapshotVendor = \is_array($payload) ? (($payload['snapshotVendor'] ?? true) !== false) : true;

        // Recovery metadata is written OUTSIDE the Guardian package BEFORE Composer.
        $this->selfMaintenance->prepareRemovalRecovery('queued', $admin);
        try {
            $job = $this->updateService->startRemove([SelfMaintenanceService::GUARDIAN_PACKAGE], $snapshotVendor, $admin);
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'start_failed', 'reason' => $e->getMessage()], 409);
        }
        $this->selfMaintenance->prepareRemovalRecovery($job->id, $admin);

        return new JsonResponse(['success' => true, 'code' => 'ok', 'job' => $this->updateService->status(), 'jobId' => $job->id, 'redirect' => $this->safeBackendRedirect()]);
    }

    private function guardSelfMaintenance(ServerRequestInterface $request): ?ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }
        if (!$this->authorization->isSystemMaintainer()) {
            return new JsonResponse(['success' => false, 'code' => 'forbidden', 'reason' => 'system_maintainer_required'], 403);
        }

        return null;
    }

    private function assertGuardianIsRootRequirement(): ?ResponseInterface
    {
        if (!$this->packageManager->isRootRequirement(SelfMaintenanceService::GUARDIAN_PACKAGE)) {
            return new JsonResponse(['success' => false, 'code' => 'not_root_require', 'reason' => 'guardian_not_root_require'], 409);
        }

        return null;
    }

    private function safeBackendRedirect(): string
    {
        foreach (['dashboard', 'web_list', 'web_layout', 'about'] as $route) {
            try {
                return (string) $this->uriBuilder->buildUriFromRoute($route);
            } catch (\Throwable) {
                // try the next known-safe route
            }
        }

        return '/typo3/';
    }

    // ── Backup ───────────────────────────────────────────────────────────────

    public function backupOptions(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }

        $components = [];
        foreach (BackupComponent::cases() as $component) {
            $components[] = [
                'key' => $component->value,
                'baseline' => $component->isBaseline(),
                'database' => $component->isDatabase(),
            ];
        }
        $grant = $this->entitlement->grant();

        return new JsonResponse([
            'success' => true,
            'components' => $components,
            'zip_supported' => ZipBackupArchiveWriter::isSupported(),
            'licensed' => $grant->isLicensed(),
            'pro' => $grant->isPro(),
        ]);
    }

    public function backupCreate(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requireLicensed()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        if ($payload === null) {
            return new JsonResponse(['success' => false, 'code' => 'invalid_payload', 'error' => 'Invalid JSON payload.'], 400);
        }
        $components = \is_array($payload['components'] ?? null) ? $payload['components'] : [];
        $selection = ComponentSelection::fromRequest($components);

        // A synchronous backup can be long-running; let it complete.
        @set_time_limit(0);
        @ignore_user_abort(true);

        try {
            $result = $this->backupService->create($selection, BackupType::Manual, $this->manualRetention());
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'backup_failed', 'error' => $e->getMessage()], 500);
        }

        return new JsonResponse([
            'success' => true,
            'code' => 'ok',
            'manifest' => $this->publicManifest($result->manifest),
            'log' => $result->log,
            'pruned' => $result->prunedCount,
        ]);
    }

    public function backupList(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }

        $backups = [];
        foreach ($this->backupStorage->list() as $manifest) {
            $backups[] = $this->publicManifest($manifest);
        }

        return new JsonResponse(['success' => true, 'backups' => $backups]);
    }

    public function backupDetails(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }

        $id = $this->requestedId($request);
        if ($id === null || !$this->backupStorage->isValidId($id)) {
            return new JsonResponse(['success' => false, 'code' => 'invalid_id', 'error' => 'Invalid backup identifier.'], 400);
        }
        $manifest = $this->backupStorage->readManifest($id);
        if ($manifest === null) {
            return new JsonResponse(['success' => false, 'code' => 'not_found', 'error' => 'Backup not found.'], 404);
        }

        $logFile = $this->backupStorage->logPath($id);
        $log = is_file($logFile) ? explode("\n", rtrim((string) @file_get_contents($logFile), "\n")) : [];

        return new JsonResponse([
            'success' => true,
            'manifest' => $this->publicManifest($manifest),
            'log' => $log,
        ]);
    }

    public function backupDownload(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }
        if (($deny = $this->requireLicensed()) !== null) {
            return $deny;
        }

        $id = $this->requestedId($request);
        if ($id === null || !$this->backupStorage->isValidId($id)) {
            return new JsonResponse(['success' => false, 'code' => 'invalid_id', 'error' => 'Invalid backup identifier.'], 400);
        }
        $archive = $this->backupStorage->archivePath($id);
        if (!is_file($archive)) {
            return new JsonResponse(['success' => false, 'code' => 'not_found', 'error' => 'Backup archive not found.'], 404);
        }

        return new Response(new Stream($archive, 'rb'), 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . $id . '.zip"',
            'Content-Length' => (string) filesize($archive),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function backupDelete(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requireLicensed()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        $id = \is_array($payload) ? (string) ($payload['id'] ?? '') : '';
        if (!$this->backupStorage->isValidId($id)) {
            return new JsonResponse(['success' => false, 'code' => 'invalid_id', 'error' => 'Invalid backup identifier.'], 400);
        }
        if (!$this->backupStorage->delete($id)) {
            return new JsonResponse(['success' => false, 'code' => 'delete_failed', 'error' => 'The backup could not be deleted.'], 500);
        }

        return new JsonResponse(['success' => true, 'code' => 'ok']);
    }

    // ── Schedule ─────────────────────────────────────────────────────────────

    public function scheduleGet(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }

        return new JsonResponse([
            'success' => true,
            'config' => $this->scheduleConfig->loadConfig(),
            'state' => $this->scheduleConfig->loadState(),
        ]);
    }

    public function scheduleSave(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        if ($payload === null) {
            return new JsonResponse(['success' => false, 'code' => 'invalid_payload', 'error' => 'Invalid JSON payload.'], 400);
        }

        return new JsonResponse(['success' => true, 'code' => 'ok', 'config' => $this->scheduleConfig->saveConfig($payload)]);
    }

    public function scheduleRun(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        $type = \is_array($payload) ? (string) ($payload['type'] ?? '') : '';
        if ($type !== 'mini' && $type !== 'full') {
            return new JsonResponse(['success' => false, 'code' => 'invalid_type', 'error' => 'Unknown schedule type.'], 400);
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        try {
            $result = $this->scheduledRunner->runProfile($type);
        } catch (GuardianException $e) {
            return new JsonResponse([
                'success' => false,
                'code' => 'backup_failed',
                'error' => $e->getMessage(),
                'state' => $this->scheduleConfig->loadState(),
            ], 500);
        }

        return new JsonResponse([
            'success' => true,
            'code' => 'ok',
            'manifest' => $this->publicManifest($result->manifest),
            'state' => $this->scheduleConfig->loadState(),
        ]);
    }

    public function testEmail(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        // Persist first (like the original), so the test uses exactly what is shown.
        $payload = $this->jsonBody($request);
        if (\is_array($payload) && $payload !== []) {
            $this->scheduleConfig->saveConfig($payload);
        }

        try {
            $recipient = $this->notifier->sendTest();
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'mail_failed', 'error' => $e->getMessage()], 500);
        }

        return new JsonResponse(['success' => true, 'code' => 'ok', 'recipient' => $recipient]);
    }

    // ── Standalone recovery panel management (admin + Pro) ────────────────────

    public function panelStatus(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        return new JsonResponse($this->panelStatePayload($request));
    }

    public function panelSaveFilename(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        $filename = \is_array($payload) ? (string) ($payload['filename'] ?? '') : '';
        $previous = $this->panelConfig->filename();

        try {
            $saved = $this->panelConfig->setFilename($filename);
            // If the panel is live, move the entrypoint to the new filename
            // (deploy new first, then remove the previous managed file).
            if ($this->panelConfig->isEnabled()) {
                $this->deployer->deploy($previous);
            }
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'invalid_filename', 'error' => $e->getMessage()], 400);
        }

        $this->panelConfig->audit('panel.filename_changed', ['from' => $previous, 'to' => $saved]);

        return new JsonResponse(['success' => true, 'code' => 'ok'] + $this->panelStatePayload($request));
    }

    public function panelDeploy(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }
        if (!$this->tokenStore->exists()) {
            return new JsonResponse(['success' => false, 'code' => 'no_token', 'error' => 'Generate an access token before enabling the recovery panel.'], 409);
        }

        try {
            $this->deployer->deploy();
            $this->panelConfig->setEnabled(true);
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'deploy_failed', 'error' => $e->getMessage()], 500);
        }

        $this->panelConfig->audit('panel.enabled', ['filename' => $this->panelConfig->filename()]);

        return new JsonResponse(['success' => true, 'code' => 'ok'] + $this->panelStatePayload($request));
    }

    public function panelDisable(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $this->deployer->remove();
        $this->panelConfig->setEnabled(false);
        $this->panelConfig->audit('panel.disabled', ['filename' => $this->panelConfig->filename()]);

        return new JsonResponse(['success' => true, 'code' => 'ok'] + $this->panelStatePayload($request));
    }

    public function panelTokenGenerate(ServerRequestInterface $request): ResponseInterface
    {
        return $this->issueToken($request, false);
    }

    public function panelRotate(ServerRequestInterface $request): ResponseInterface
    {
        return $this->issueToken($request, true);
    }

    public function panelTest(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $path = $this->deployer->deployedPath();

        return new JsonResponse([
            'success' => true,
            'code' => 'ok',
            'deployed' => $path !== null,
            'managed' => $path !== null,
            'url' => $this->panelUrl($request, $this->panelConfig->filename()),
        ]);
    }

    // ── Recovery flow (reuses the shared recovery services; admin + Pro) ──────

    public function recoveryList(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $backups = [];
        foreach ($this->recoveryCatalog->recoverableList() as $manifest) {
            $backups[] = $this->publicManifest($manifest);
        }

        return new JsonResponse(['success' => true, 'backups' => $backups]);
    }

    public function recoveryPreflight(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        if ($payload === null) {
            return new JsonResponse(['success' => false, 'code' => 'invalid_payload', 'error' => 'Invalid JSON payload.'], 400);
        }
        $backupId = (string) ($payload['backupId'] ?? '');
        $components = \is_array($payload['components'] ?? null) ? $payload['components'] : [];

        return new JsonResponse(['success' => true, 'preflight' => $this->preflight->run($backupId, $components)]);
    }

    public function recoveryRun(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        if ($payload === null) {
            return new JsonResponse(['success' => false, 'code' => 'invalid_payload', 'error' => 'Invalid JSON payload.'], 400);
        }
        $backupId = (string) ($payload['backupId'] ?? '');
        $components = \is_array($payload['components'] ?? null) ? $payload['components'] : [];
        $confirm = ($payload['confirm'] ?? false) === true;
        $phrase = strtoupper(trim((string) ($payload['phrase'] ?? '')));
        $vendorStrategy = (string) ($payload['vendorStrategy'] ?? 'rebuild');
        $vendorPhrase = strtoupper(trim((string) ($payload['vendorPhrase'] ?? '')));

        if (!$confirm || $phrase !== 'RECOVER') {
            return new JsonResponse(['success' => false, 'code' => 'not_confirmed', 'error' => 'Type RECOVER and tick the confirmation box to proceed.'], 400);
        }

        // Hard server-side rejection of the legacy unsafe vendor flag: vendor is
        // controlled ONLY by vendorStrategy and never restored in place.
        if (($components['vendor'] ?? null) === true) {
            return new JsonResponse(['success' => false, 'code' => 'vendor_flag_forbidden', 'error' => 'Direct vendor restore is disabled. Choose a vendor strategy instead.'], 400);
        }
        // Archived vendor restore requires an explicit second confirmation.
        if ($vendorStrategy === 'archived' && $vendorPhrase !== 'RESTORE VENDOR') {
            return new JsonResponse(['success' => false, 'code' => 'vendor_not_confirmed', 'error' => 'Archived vendor restore requires typing RESTORE VENDOR.'], 400);
        }
        // A successful dry run for this exact selection is mandatory.
        $fingerprint = RecoveryDryRun::fingerprint($backupId, $components, \Vtinnovations\GuardianTypo3\Domain\Recovery\VendorRestoreStrategy::fromString($vendorStrategy)->value);
        if (!$this->recoveryDryRun->matchesLastDryRun($fingerprint)) {
            return new JsonResponse(['success' => false, 'code' => 'dry_run_required', 'error' => 'Run a successful dry run for this exact selection before recovering.'], 409);
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        try {
            // Safety snapshot is always taken (server-enforced true).
            $result = $this->restoreService->restore($backupId, $components, true, true, $vendorStrategy);
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'recovery_failed', 'error' => $e->getMessage()], 500);
        }

        return new JsonResponse([
            'success' => true,
            'code' => 'ok',
            'result' => [
                'backupId' => $result->backupId,
                'restored' => $result->restoredComponents,
                'safetySnapshotId' => $result->safetySnapshotId,
                'rolledBack' => $result->rolledBack,
                'log' => $result->log,
            ],
        ]);
    }

    public function recoveryDryRun(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        if ($payload === null) {
            return new JsonResponse(['success' => false, 'code' => 'invalid_payload', 'error' => 'Invalid JSON payload.'], 400);
        }
        $backupId = (string) ($payload['backupId'] ?? '');
        $components = \is_array($payload['components'] ?? null) ? $payload['components'] : [];
        if (($components['vendor'] ?? null) === true) {
            unset($components['vendor']); // vendor is controlled by strategy only
        }
        $vendorStrategy = (string) ($payload['vendorStrategy'] ?? 'rebuild');

        @set_time_limit(0);
        try {
            $result = $this->recoveryDryRun->run($backupId, $components, $vendorStrategy);
        } catch (GuardianException $e) {
            $this->recoveryDryRun->invalidate();

            return new JsonResponse(['success' => false, 'code' => 'dry_run_failed', 'error' => $e->getMessage()], 400);
        }

        return new JsonResponse(['success' => true, 'code' => 'ok', 'dryRun' => $result]);
    }

    public function recoveryInterrupted(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        return new JsonResponse(['success' => true, 'incomplete' => $this->recoveryJournal->findIncomplete()]);
    }

    public function recoveryRollbackInterrupted(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        $payload = $this->jsonBody($request);
        $jobId = \is_array($payload) ? (string) ($payload['jobId'] ?? '') : '';

        @set_time_limit(0);
        @ignore_user_abort(true);
        try {
            $result = $this->restoreService->rollbackInterrupted($jobId);
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'rollback_failed', 'error' => $e->getMessage()], 500);
        }

        return new JsonResponse(['success' => true, 'code' => 'ok', 'rolledBack' => $result->rolledBack, 'log' => $result->log]);
    }

    public function recoveryHistory(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        return new JsonResponse(['success' => true, 'history' => $this->recoveryHistory->list()]);
    }

    // ── Guards / helpers ────────────────────────────────────────────────────

    private function issueToken(ServerRequestInterface $request, bool $rotate): ResponseInterface
    {
        if (($deny = $this->guardPost($request)) !== null) {
            return $deny;
        }
        if (($deny = $this->requirePro()) !== null) {
            return $deny;
        }

        try {
            // The plaintext is returned exactly once here and never persisted or logged.
            $token = $rotate ? $this->tokenStore->rotate() : $this->tokenStore->generate();
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'token_failed', 'error' => $e->getMessage()], 500);
        }

        $this->panelConfig->audit($rotate ? 'token.rotated' : 'token.generated');

        // `plainToken` is the reveal-once secret (never persisted); `token` in the
        // shared payload is the non-sensitive masked status.
        return new JsonResponse($this->panelStatePayload($request) + [
            'code' => 'ok',
            'plainToken' => $token,
            'rotated' => $rotate,
        ]);
    }

    /**
     * Non-sensitive panel state for the UI (never the plaintext token).
     *
     * @return array<string, mixed>
     */
    private function panelStatePayload(ServerRequestInterface $request): array
    {
        $filename = $this->panelConfig->filename();

        return [
            'success' => true,
            'enabled' => $this->panelConfig->isEnabled(),
            'deployed' => $this->deployer->isDeployed(),
            'filename' => $filename,
            'defaultFilename' => \Vtinnovations\GuardianTypo3\Domain\Recovery\PanelFilename::DEFAULT,
            'url' => $this->panelUrl($request, $filename),
            'token' => $this->tokenStore->status(),
        ];
    }

    private function panelUrl(ServerRequestInterface $request, string $filename): string
    {
        $uri = $request->getUri();
        $scheme = $uri->getScheme() !== '' ? $uri->getScheme() : 'https';
        $host = $uri->getHost();
        $port = $uri->getPort();
        $authority = $host . ($port !== null && !\in_array($port, [80, 443], true) ? ':' . $port : '');

        return $scheme . '://' . $authority . '/' . $filename;
    }

    private function guard(): ?ResponseInterface
    {
        try {
            $this->authorization->assertAdministrator();
        } catch (GuardianException $e) {
            return new JsonResponse(['success' => false, 'code' => 'forbidden', 'error' => $e->getMessage()], 403);
        }

        return null;
    }

    private function guardPost(ServerRequestInterface $request): ?ResponseInterface
    {
        if (($deny = $this->guard()) !== null) {
            return $deny;
        }
        if (strtoupper($request->getMethod()) !== 'POST') {
            return new JsonResponse(['success' => false, 'code' => 'method_not_allowed', 'error' => 'Method not allowed.'], 405);
        }

        return null;
    }

    /**
     * The lower requirement: any tier in effect, Free included. Manual backup is
     * what this guards; everything that changes the installation asks for
     * {@see requirePro()} instead.
     */
    private function requireLicensed(): ?ResponseInterface
    {
        if (!$this->entitlement->isLicensed()) {
            return new JsonResponse([
                'success' => false,
                'code' => 'license_required',
                'error' => 'This feature requires at least a Free license from v-t.one.',
            ], 403);
        }

        return null;
    }

    private function requirePro(): ?ResponseInterface
    {
        if (!$this->entitlement->isPro()) {
            return new JsonResponse([
                'success' => false,
                'code' => 'pro_required',
                'error' => 'This feature requires a valid Pro license from v-t.one.',
            ], 403);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonBody(ServerRequestInterface $request): ?array
    {
        $decoded = json_decode((string) $request->getBody(), true);

        return \is_array($decoded) ? $decoded : null;
    }

    private function requestedId(ServerRequestInterface $request): ?string
    {
        $params = $request->getQueryParams();
        if (isset($params['id']) && \is_string($params['id'])) {
            return $params['id'];
        }
        $body = $this->jsonBody($request);

        return \is_array($body) && isset($body['id']) ? (string) $body['id'] : null;
    }

    private function manualRetention(): int
    {
        // Manual backups are pruned generously; the scheduled profiles have their
        // own retention. Keep the most recent 30 manual backups.
        return 30;
    }

    /**
     * Adds a tokenised download URL and strips nothing sensitive (manifests hold
     * no credentials). Filesystem paths are intentionally not exposed.
     *
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function publicManifest(array $manifest): array
    {
        $id = (string) ($manifest['id'] ?? '');
        $manifest['download_url'] = null;
        if ($this->backupStorage->isValidId($id)) {
            try {
                $manifest['download_url'] = (string) $this->uriBuilder->buildUriFromRoute('ajax_guardian_backup_download', ['id' => $id]);
            } catch (RouteNotFoundException) {
                $manifest['download_url'] = null;
            }
        }

        return $manifest;
    }
}
