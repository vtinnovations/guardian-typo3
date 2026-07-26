# Feature Matrix — Contao → TYPO3 (13.4 / 14)

Maps every Guardian feature from the Contao original to its intended TYPO3
implementation. The chosen TYPO3 APIs are the shared subset available in both
TYPO3 13.4.9 and 14 (see `CompatibilityStrategy.md`). "Phase 1 status" reflects
what exists in THIS scaffold.

Columns:
- **Entitlement**: Free / Pro / n/a (infrastructure).
- **Class**: **D**estructive or **N**on-destructive.
- **Phase**: target implementation phase (see `ImplementationRoadmap.md`).
- **P1**: Phase-1 status — ✅ done · ◑ foundation only · ⏳ deferred.

## Concept mapping (Contao → TYPO3 13.4 / 14)

| Contao concept | TYPO3 13.4 / 14 replacement | API / service |
|---|---|---|
| Contao backend module (menu listener) | Backend module registration | `Configuration/Backend/Modules.php` (`access: admin`) |
| `AbstractBackendController` + `@Contao/be_main` | Module controller + `ModuleTemplate` + Fluid | `ModuleTemplateFactory`, `BackendViewFactory` |
| `BackendAuthChecker` (`ROLE_ADMIN`) | Backend admin check | `$GLOBALS['BE_USER']->isAdmin()` via adapter + module `access: admin` |
| Contao attribute routes | Backend module routing / AJAX routes | `Configuration/Backend/Modules.php`, `Configuration/Backend/AjaxRoutes.php` |
| `#[AsCronJob('minutely')]` (kernel.terminate) | TYPO3 Scheduler task or console command in crontab | `scheduler` ext / `Configuration/Services.yaml` console command |
| Contao `tl_log` via monolog channel | TYPO3 logging framework | `Psr\Log\LoggerInterface` (LogManager), `sys_log` |
| `contao-console cache:clear` | TYPO3 cache flush | core `CacheManager` |
| `contao:maintenance-mode` + `var/maintenance.html` | Documented lock-file maintenance state | custom adapter (`MaintenanceModeInterface`) |
| `contao:migrate` | Schema migration + upgrade wizards | install-tool schema service / `database:updateschema` |
| `%kernel.project_dir%`, `var/updater/` | `Environment` paths, `var/guardian/` | `TYPO3\CMS\Core\Core\Environment` |
| Symfony Mailer injection | TYPO3 Mailer | `TYPO3\CMS\Core\Mail\Mailer` via adapter |
| Contao Manager plugin | (none needed) | Composer + `ext_*`/`Configuration/*` |
| 176 KB backend Twig template | Fluid layout + templates + partials + external CSS/JS | `Resources/Private/{Layouts,Templates,Partials}` |
| `.env` `DATABASE_URL` parsing | TYPO3 DB connection config | `ConnectionPool` / `$GLOBALS['TYPO3_CONF_VARS']['DB']` |

## Feature-by-feature

| Feature | Contao implementation | TYPO3 implementation | Entitlement | Class | Phase | P1 |
|---|---|---|---|---|---|---|
| Backend dashboard | Twig tab, `StatusManager` | Module `dashboard` action + Fluid, `DashboardService` | Free | N | 1 | ✅ |
| Extension/version identity | reads `installed.json` | `ExtensionInformation` (own composer.json) | n/a | N | 1 | ✅ |
| Environment capability model | `PlatformChecker`, `PreUpdateChecker` | `EnvironmentInspector` → `EnvironmentCapabilities` (no exec) | n/a | N | 1 | ✅ |
| Runtime config (read) | `RuntimeConfig` | `RuntimeConfiguration` VO + JSON repo + read-only Settings view | n/a | N | 1 | ✅ |
| Runtime config (write) | `RuntimeConfigController::save` | guarded write route (admin + CSRF) | n/a | N | 2 | ⏳ |
| PHP CLI binary detect/test | `RuntimeConfig::testBinary` (exec) | command-executor probe | n/a | N | 6/8 | ⏳ |
| License read/interpret | `LicenseManager` | `LicenseState` VO + `LicenseManager` | n/a | N | 1 | ✅ |
| License activate/verify | `LicenseVerifier` (HTTP) | verifier port + TYPO3 HTTP adapter | admin + CSRF | Y | 2 | ✅ |
| Free/Pro feature gates | `LicenseGuard` | `LicenseGuard` server-side gate | n/a | N | 2 | ✅ |
| Installed/outdated packages | `PackageInspector` (Packagist) | package inspector behind HTTP port | Free | N | 6 | ⏳ |
| Update analysis / pre-checks | `PreUpdateChecker`, `AnalysisController` | analysis service (read + executor) | Pro | N | 6 | ⏳ |
| Composer update (full/patch/selective) | `ComposerUpdateStep` | update pipeline via `CommandExecutorInterface` | Pro | D | 8 | ⏳ |
| Major-version update | `ConstraintBump`/`DryRun`/`BootCheck` | (excluded — disabled in product) | Pro | D | — | ⏳ |
| Background job runner | `JobRunner`, `RunJobCommand`, `UpdateJobManager` | TYPO3 console worker + job persistence | Pro | D | 7 | ⏳ |
| Job state & logs | `UpdateJob`, `JobLog` | `Job` VO (guarded transitions) + append log/redaction | Pro | D | 7 | ◑ |
| Pre-update snapshot | `PreSnapshotStep`/`BackupStep` | snapshot step in pipeline | Pro | D | 8 | ⏳ |
| Manual backup (DB + files) | `BackupManager` | backup service (TYPO3 layout) | Free | D | 3 | ⏳ |
| Backup listing | `BackupManager::listBackups` | backup repository (read) | Free | N | 4 | ⏳ |
| Backup retention | `ScheduledBackupRunner::applyRetention` | retention policy service | Pro | D | 4 | ⏳ |
| Backup deletion | `BackupManager::deleteBackup` | guarded delete route | Free | D | 4 | ⏳ |
| Restore / rollback | `RestoreManager` | restore pipeline + `ArchiveEntryValidator` | Pro | D | 9 | ◑ |
| Archive traversal guard | inline in `RestoreManager` | `ArchiveEntryValidator` (ported now) | n/a | N | 1 | ✅ |
| Maintenance mode | `Maintenance{On,Off}Step` | `MaintenanceModeInterface` adapter | Pro | D | 9 | ⏳ |
| DB migrations | `MigrateStep` | `DatabaseSchemaUpdaterInterface` adapter | Pro | D | 8 | ⏳ |
| Scheduled mini/full backups | `ScheduledBackupRunner`, `BackupCron` | Scheduler task + `ScheduleForecastService` | Pro | D | 5 | ◑ |
| Schedule config & forecast | `ScheduleConfig`/`ScheduleEvaluator`/`ScheduleState` | `BackupSchedule`/`ScheduleEvaluator`/`ScheduleRun` (read + forecast) | Pro | N | 1/5 | ✅ (eval) |
| Scheduler locking | `BackupLock` | `FlockLock` + `LockFactoryInterface` | n/a | N | 1 | ✅ |
| Notifications (email) | `BackupNotifier`, `RecoveryEmailNotifier` | `MailerInterface` adapter + notifiers | Pro | N | 10 | ⏳ |
| Standalone recovery panel | `_updater-recovery.php` | separate hardened deliverable (NOT copied) | Pro | D | 11 | ⏳ |
| Recovery token & brute-force | `PanelAuth` | recovery-panel security package | Pro | D | 11 | ⏳ |
| Admin authorization | `BackendAuthChecker` | `BackendAuthorizationInterface` + module `access: admin` | n/a | N | 1 | ✅ |
| CSRF protection | `CsrfRequestListener` | TYPO3 backend request tokens per write route | n/a | N | 2 | ⏳ |
| Audit/system logging | `SystemLogger` (`tl_log`) | `SystemLoggerInterface` → TYPO3 logging | n/a | N | 1 | ✅ |
| Update badge / system message | `BackendMenuListener`, `SystemMessagesListener` | optional dashboard widget | Free | N | 6 | ⏳ |

## Deliberately excluded / deferred in Phase 1

- Any destructive operation (backup, update, restore, delete, maintenance,
  migration) — no such code path exists yet; the executor refuses to run.
- The standalone recovery panel — treated as a separate later security-critical
  deliverable; the Contao file is **not** copied into this project.
- Major-version upgrade mode — already disabled in the Contao product.
- License activation and any outbound HTTP.
- Any write endpoint / form submission in the backend UI.
