# Contao Source Audit — `vtinnovations/guardian` (Contao 5)

This document records a full static inspection of the original Contao 5 bundle
(`guardian.zip`) that this TYPO3 13.4 / 14 extension is ported from. Every meaningful
source file was read in full. Nothing from the Contao tree is copied verbatim
into the TYPO3 project; this audit is the basis for the redesign.

> Scope of inspection: all PHP, YAML, Twig, JavaScript, CSS and documentation
> files. Excluded from any porting consideration: `.git/`, `__MACOSX/`,
> `.DS_Store`, the Contao Manager plugin, and Contao/Symfony bundle bootstrapping.

## 1. Bundle shape

| Property | Value |
|---|---|
| Package | `vtinnovations/guardian` (`type: contao-bundle`) |
| Namespace | `Vtinnovations\Guardian\` → `src/` |
| Runtime deps | `contao/core-bundle ^5.3`, `symfony/process`, `symfony/console`, PHP `^8.2` |
| Integration | Contao Manager plugin (bundle + route registration) |
| Working data | `var/updater/` (JSON job/state/logs/config/license/token, backups) |
| Backend UI | one 176 KB Twig template extending `@Contao/be_main`, ~3370 lines, inline `<style>` + two `<script>` blocks, ~20 tabs |
| Standalone panel | `public/_updater-recovery.php` (53 KB, zero-dependency) |

## 2. External binaries & side-effect surfaces

| Concern | Where | Notes |
|---|---|---|
| PHP CLI | `RuntimeConfig`, `UpdateJobManager`, `CommandRunner`, `PlatformChecker`, `ComposerUpdateStep` | explicit binary path resolution; exec `-v` probing to bypass `open_basedir` |
| composer.phar | `ComposerUpdateStep`, `PreUpdateChecker` | always run as `<php> composer.phar` (never shell wrapper) |
| mysqldump / mysql | `BackupManager`, `RestoreManager` | password via `MYSQL_PWD` env, `proc_open('/bin/sh -c …')` pipelines |
| tar / gzip / gunzip | `BackupManager`, `RestoreManager` | `exec()` / `Process` with `escapeshellarg` |
| rm -rf | `BackupManager::removeDir`, `RestoreManager::removeDir` | `exec('rm -rf ' . escapeshellarg($dir))` |
| Worker spawn | `UpdateJobManager::startWorker` | detached `nohup … &` via Symfony Process / `exec` / `shell_exec` |
| HTTP out | `LicenseVerifier` (license server), `PackageInspector`/`CompatibilityAnalyzer` (Packagist via curl) | |
| DB credentials | `BackupManager`, `RestoreManager` | parsed from `.env(.local)` `DATABASE_URL` |
| Auth tokens | `PanelAuth`, `_updater-recovery.php` | `var/updater/access.token` (0600) or `VTINNOVATIONS_GUARDIAN_TOKEN` env |
| Filesystem writes | throughout `var/updater/`, plus `public/<panel>.php` deploy | |

## 3. Class-by-class mapping

Legend for **Disposition**: **Port** = logic is CMS-independent, carry the rules
over; **Adapt** = keep the idea, re-target the framework integration; **Redesign**
= concept kept but implementation rebuilt for TYPO3; **Discard** = Contao-specific
glue with no TYPO3 equivalent needed.

### Bundle / integration

| Class | Responsibility | Depends on | Nature | Security | Disposition |
|---|---|---|---|---|---|
| `Guardian` (Bundle) | Bundle class; deploys/removes recovery panel on boot via env flag | Symfony Bundle, `$_SERVER`/`$_ENV`, filesystem | Contao/Symfony | copies executable PHP into webroot | **Redesign** → recovery panel becomes a later, opt-in, security-reviewed deliverable; no boot-time file copy |
| `ContaoManager\Plugin` | Registers bundle + routes with Contao Manager | Contao Manager API | Contao | — | **Discard** (TYPO3 uses `ext_*`/`Configuration/*` conventions) |
| `DependencyInjection\GuardianExtension` | Symfony DI extension loading `services.yaml` | Symfony DI | Contao/Symfony | — | **Discard** (TYPO3 auto-loads `Configuration/Services.yaml`) |
| `config/services.yaml` | Service wiring, `%kernel.project_dir%` args | Symfony DI | Symfony | — | **Redesign** → `Configuration/Services.yaml` with ports/adapters |
| `config/routes.yaml` | Attribute route loader for controllers | Symfony routing | Symfony | — | **Discard** (TYPO3 backend module routing) |

### Controllers (backend API)

All extend `AbstractBackendController`, gate on `BackendAuthChecker::assertAdmin()`
and (for paid features) `LicenseGuard`. Routes use `%contao.backend.route_prefix%`.

| Class | Responsibility | Nature | Disposition |
|---|---|---|---|
| `BackendController` | Renders the Twig dashboard shell | Contao (`AbstractBackendController`, `@Contao/be_main`) | **Redesign** → TYPO3 backend module controller |
| `JobController` | start/status/log/rollback/archive/clear-stale of update jobs (POST JSON) | Contao + job system | **Redesign** (later phase; destructive) |
| `BackupController` | create/list/delete backups | Contao + BackupManager | **Redesign** (later phase) |
| `RuntimeConfigController` | get/save/test PHP binary, test recovery email | Contao + RuntimeConfig | **Adapt** (read now, guarded write later) |
| `ScheduleController` | get/save schedule, run-now, test email | Contao + schedule | **Redesign** (later phase) |
| `LicenseController` | status/activate/clear license | Contao + LicenseManager | **Adapt** (read now, activate later) |
| `PanelSettingsController` | get/rotate recovery token | Contao + PanelAuth | **Redesign** (recovery phase) |
| `AnalysisController` | pre-update checks (invoke) | Contao + PreUpdateChecker | **Redesign** (later) |
| `PackagesController` | list installed/outdated packages | Contao + PackageInspector | **Adapt** (read-only inspection) |

### Domain-ish services (mostly CMS-independent)

| Class | Responsibility | CMS coupling | Disposition |
|---|---|---|---|
| `Service\RuntimeConfig` | Load/save/validate `runtime.json`; PHP-binary detect/test | `$projectDir` string + `exec()` for testing | **Port** (validation → immutable `RuntimeConfiguration`; exec probing → later phase) |
| `Service\StatusManager` | Read `status.json` state | `$projectDir` | **Port** (folded into status/job read models) |
| `Service\PackageInspector` | List installed, query Packagist for latest | curl/http, filesystem cache | **Adapt** (later; HTTP behind a port) |
| `Service\UpdateNotifier` | Read cached outdated-count for badges | filesystem | **Adapt** (later) |
| `Service\PlatformChecker` | Web-vs-CLI extension mismatch → `--ignore-platform-req` | `exec()` CLI PHP | **Redesign** (update phase; needs command executor) |
| `Service\CompatibilityAnalyzer` | Pre-bump major-upgrade analysis via Packagist | curl | **Redesign** (major-upgrade excluded) |
| `Service\MajorVersionInspector` | Composer `why-not`/version probing | `Process` | **Redesign** (excluded) |
| `Service\SystemLogger` | Writes to Contao `tl_log` via monolog channel | Contao `ContaoContext` | **Redesign** → `SystemLoggerInterface` + TYPO3 logging adapter |
| `Checker\PreUpdateChecker` | Disk space, writability, composer presence checks | filesystem + `shell_exec('which')` | **Adapt** (env inspection; exec later) |

### Backup / restore

| Class | Responsibility | Nature | Security | Disposition |
|---|---|---|---|---|
| `Backup\BackupManager` | Create/list/delete backups (DB dump + tar/zip of dirs), PHP fallbacks | filesystem, `exec`, `proc_open`, PDO | DB creds, `rm -rf`, mkdir diagnostics | **Redesign** (backup phase; components re-mapped to TYPO3 layout) |
| `Restore\RestoreManager` | Restore files + DB from backup; zip/tar-slip guards; consistency analysis | filesystem, `Process`, `proc_open`, PDO | destructive; archive traversal; DB import | **Redesign** (restore phase; **zip-slip rule ported now** as `ArchiveEntryValidator`) |

### Job system (background worker)

| Class | Responsibility | Nature | Disposition |
|---|---|---|---|
| `Job\UpdateJob` | Job value object; pipeline/step composition; modes | pure (business rules) | **Port** → immutable `Job` + `JobStatus`/`JobType`/`UpdateMode` with guarded transitions |
| `Job\UpdateJobManager` | Persist/queue/archive jobs; spawn detached worker; stale detection | filesystem, `Process`/`exec`/`shell_exec` | **Redesign** (job persistence + worker are later phases) |
| `Job\JobRunner` | Execute steps in order; cleanup steps; failure attribution | orchestration | **Redesign** (later; concept preserved) |
| `Job\JobLog` | Append-only JSON log; **secret redaction** | filesystem | **Port** (redaction rules ported into logging adapter) |
| `Job\Step\StepInterface` | Step contract | pure | **Adapt** (re-modelled per pipeline phase) |
| `Job\Step\CommandRunner` | Run commands via `Process`; detect PHP scripts vs shell wrappers | `Process`, `exec` fallbacks | **Redesign** → `CommandExecutorInterface` + `CommandRequest`/`CommandResult` |
| `Job\Step\ComposerUpdateStep` | `composer update` (full/patch/selective) | `Process` | **Redesign** (update phase) |
| `Job\Step\CacheClearStep` | `contao-console cache:clear` | `Process` | **Redesign** → `CacheManagerInterface` |
| `Job\Step\MigrateStep` | `contao:migrate` | `Process` | **Redesign** → `DatabaseSchemaUpdaterInterface` |
| `Job\Step\Maintenance{On,Off}Step` | toggle maintenance mode | delegates | **Redesign** → `MaintenanceModeInterface` |
| `Job\Step\{Backup,PreSnapshot,Restore}Step` | wrap backup/restore managers | delegates | **Redesign** (respective phases) |
| `Job\Step\{ConstraintBump,DryRunCheck,BootCheck}Step` | major-upgrade pre-flight | `Process` | **Discard/Defer** (major upgrade disabled in product) |
| `Command\RunJobCommand` | CLI worker entry (`guardian:run-job`) | Symfony Console | **Redesign** → TYPO3 console command (later) |
| `Job\Exception\{JobBlocked,WorkerSpawn}Exception` | typed job errors | pure | **Port** (as needed per phase) |

### Schedule

| Class | Responsibility | Nature | Disposition |
|---|---|---|---|
| `Schedule\ScheduleEvaluator` | Decide "is due" / "next run" | **pure, CMS-independent** | **Port** (near-verbatim → `Domain\Schedule\ScheduleEvaluator`) |
| `Schedule\ScheduleConfig` | Load/validate `schedule.json`; storage-path safety checks | `$projectDir`, filesystem | **Port** (VOs `BackupSchedule` + validation; path safety → security phase) |
| `Schedule\ScheduleState` | Track last-run/in-progress | filesystem | **Port** → `ScheduleRun` VO + repository |
| `Schedule\BackupLock` | `flock` sentinel with stale-age guard | filesystem | **Port** → `FlockLock` + `LockInterface` |
| `Schedule\ScheduledBackupRunner` | Orchestrate due backups + retention + notify | delegates | **Redesign** (scheduler phase) |
| `Cron\BackupCron` | `#[AsCronJob('minutely')]` in kernel.terminate | Contao Cron | **Redesign** → `SchedulerIntegrationInterface` (TYPO3 Scheduler/crontab) |

### Security / licensing

| Class | Responsibility | Nature | Disposition |
|---|---|---|---|
| `Security\BackendAuthChecker` | Admin-only gate (`ROLE_ADMIN`) | Contao `BackendUser` + Symfony Security | **Redesign** → `BackendAuthorizationInterface` + TYPO3 admin adapter |
| `Security\CsrfRequestListener` | Same-origin CSRF for state-changing routes | Symfony kernel event | **Redesign** (TYPO3 provides backend CSRF tokens; re-implement per write route) |
| `Security\LicenseManager` | Cache/interpret license; grace window; activate/refresh | filesystem + verifier | **Port** (interpretation → `LicenseState` VO; persistence + activation split) |
| `Security\LicenseVerifier` | HTTP verify against `v-t.one` | Symfony HttpClient | **Adapt** (behind a verifier port, later phase) |
| `Security\LicenseGuard` | Pro/Free gate; JSON 403 responses | Contao JsonResponse | **Ported** → framework-neutral `LicenseGuard` |
| `External\PanelAuth` | Recovery token storage + request auth (Basic/Bearer), same-origin, brute-force | filesystem, `$_SERVER` | **Redesign** (recovery phase; security-critical) |

### Notifications / listeners / UI

| Class | Responsibility | Nature | Disposition |
|---|---|---|---|
| `Notifier\BackupNotifier` | Backup success/failure emails (rate-limited) | Symfony Mailer | **Redesign** → `MailerInterface` (notifications phase) |
| `Notifier\RecoveryEmailNotifier` | Pre-update recovery email with panel URL + token | Symfony Mailer, RequestStack | **Redesign** (recovery phase; sensitive) |
| `EventListener\BackendMenuListener` | Adds Contao backend menu item + update badge | Contao MenuEvent | **Discard** → replaced by TYPO3 `Configuration/Backend/Modules.php` |
| `EventListener\SystemMessagesListener` | Backend home "updates available" message | Contao `getSystemMessages` hook | **Discard/Defer** (optional TYPO3 dashboard widget later) |
| `templates/backend/…twig` | Entire backend UI (tabs, CSS, JS inline) | Twig `@Contao/be_main` | **Redesign** → decomposed Fluid layout/templates/partials + external CSS/JS |
| `public/_updater-recovery.php` | Standalone recovery panel | zero-dep PHP, executes restore/DB import | **Redesign** (separate, later, security-critical deliverable — NOT in Phase 1) |

## 4. Reusable business rules identified for immediate port

- Schedule due/next-run evaluation (interval vs slot strategies).
- License grace-window + tier interpretation.
- Runtime-config field validation (PHP binary, emails, panel filename pattern).
- Archive entry safety (absolute-path / `..` rejection) — zip/tar-slip guard.
- Lexical path normalisation + containment (symlink-agnostic).
- `flock` lock with stale-age reclaim.
- Job lifecycle state machine.
- Secret redaction patterns for logs.
- Safe process invocation model (argv array, never a shell string; secrets via env).

## 5. Security observations in the original (carried into `SecurityModel.md`)

1. **Recovery panel is a live restore/DB-import tool in the webroot**, protected
   only by a token + brute-force lockout; deployment was opt-in but boot-time.
   The strongest attack surface in the product.
2. **DB credentials** are read from `.env` and passed to child processes; kept out
   of `argv` via `MYSQL_PWD` (good), but pipelines still run through `/bin/sh -c`.
3. **`exec()`/`shell_exec()` probing** is used widely to work around `open_basedir`
   — pragmatic but expands the process-execution surface.
4. **Job logs** live in `var/updater/` and are shown by the recovery panel; secret
   redaction mitigates but the log is broadly readable.
5. **Same-origin-only CSRF** (Origin/Referer) rather than a token — reasonable, but
   TYPO3's native request-token model is stronger and should be used.
6. **License API secret** is compiled into `LicenseVerifier` — acceptable for a
   shared product key but should be treated as low-value/rotatable.
