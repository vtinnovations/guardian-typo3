# Contao → TYPO3 UI Parity Matrix

Maps every visual section and interactive control of the authoritative Contao
Guardian interface (`templates/backend/vtinnovations_guardian.html.twig`, plus
its controllers) to its TYPO3 implementation in this extension.

**Status legend:** ✅ live (real endpoint) · 🔒 disabled-with-reason (backend
phase not shipped) · 🖥️ client-only (pure UI, no backend needed).

## Endpoint mapping (Contao controller → TYPO3 route)

| Contao route / action | Responsibility | TYPO3 route (`AjaxRoutes.php`) | TYPO3 controller method | Service | Status |
|---|---|---|---|---|---|
| `vtinnovations_guardian_license_status` (`LicenseController::status`) | read license state | `guardian_license_status` | `GuardianAjaxController::licenseStatus` | `LicenseManager` | ✅ |
| `..._packages` (`PackagesController`) | list installed packages | `guardian_packages` | `::packages` | `InstalledPackages` | ✅ (installed only; Packagist later) |
| `..._backup_list` (`BackupController::list`) | list backups | `guardian_backup_list` | `::backupList` | `BackupListReader` | ✅ (empty until backup phase) |
| `..._schedule_get` (`ScheduleController::get`) | read schedule config | `guardian_schedule_get` | `::scheduleGet` | `ScheduleRepositoryInterface` | ✅ |
| `..._runtime_get` (`RuntimeConfigController::get`) | read runtime config | `guardian_runtime_get` | `::runtimeGet` | `RuntimeConfigurationService` | ✅ |
| `..._panel_get` (`PanelSettingsController::get`) | recovery token source | `guardian_panel_get` | `::panelGet` | `RecoveryTokenReader` | ✅ (source only; never reveals/generates) |
| `..._analyse` (`AnalysisController`) | pre-update checks | `guardian_analyse` | `::analyse` | `PreUpdateAnalysis` | ✅ (read-only, no exec) |
| `..._job_status` (`JobController::status`) | active job status | `guardian_job_status` | `::jobStatus` | — | ✅ (reports idle) |
| `..._job_archive` (`JobController::archive`) | job history | `guardian_job_archive` | `::jobArchive` | — | ✅ (empty) |
| `..._backup_create` (`BackupController::create`) | create backup | — | — | `BackupManager` (later) | 🔒 button disabled |
| `..._backup_delete` (`BackupController::delete`) | delete backup | — | — | (later) | 🔒 |
| `..._job_start` (`JobController::start`) | start update/dry-run | — | — | `UpdateJobManager` (later) | 🔒 |
| `..._job_rollback` / `_log` / `_clear_stale` | rollback / live log / clear | — | — | (later) | 🔒 |
| `..._schedule_save` / `_run` / `_test_email` | save/run schedule, test mail | — | — | `ScheduledBackupRunner` (later) | 🔒 |
| `..._license_activate` / `_clear` | activate/clear license | Guardian AJAX routes | `GuardianAjaxController` | `LicenseManager`/`VtOneLicenseVerifier` | ✅ |
| `..._runtime_save` / `_test` / `_test_recovery_email` | save/test PHP binary, test mail | — | — | (later) | 🔒 |
| `..._panel_rotate` (`PanelSettingsController::rotate`) | rotate token | — | — | `PanelAuth` (recovery phase) | 🔒 |

No route exists for any destructive/write action — those controls render
`disabled` in the markup, so nothing is wired to a placeholder and nothing fakes
success (per the functionality-status rules).

## Section & control mapping

| Original section / control | Twig lines | Original CSS classes | Original JS handler | TYPO3 partial | Endpoint | License | Status |
|---|---|---|---|---|---|---|---|
| Primary tabs (5) | 434–440 | `.updater-tabs`, `.updater-tab-btn`, `.updater-tab-content` | `updaterSwitchTab`, `updaterInitTabs` | `Partials/Guardian/Tabs` | — | — | 🖥️ |
| Locked-tab upgrade dialog | 1284–1309 | `.updater-upgrade-modal` | `updaterOpenUpgradeModal` | `Templates/Guardian/Index` | — | Free/none | 🖥️ |
| Dashboard plan badge + features | 446–484 | `.updater-plan-badge`, `.updater-plan-card`, `.updater-plan-features` | `updaterRenderPlan` | `Partials/Guardian/Dashboard` | licenseStatus | — | ✅ |
| Dashboard stats grid | 487–500 | `.updater-grid`, `.updater-stat` | (server) | Dashboard | — | — | ✅ (TYPO3 version/pkg count/backups) |
| Dashboard status card | 503–542 | `.updater-card`, `.updater-status-badge` | `updaterRenderJob` | Dashboard | jobStatus | — | ✅ (idle) |
| Pre-update analysis | 545–554 | `.updater-check-row`, `.updater-result-summary` | `updaterRunAnalysis` | Dashboard | analyse | — | ✅ |
| Package table + filter | 557–583 | `.updater-pkg-controls`, `.updater-pkg-table`, `.updater-pkg-tag` | `updaterLoadPackages`, `updaterApplyFilter` | Dashboard | packages | Free | ✅ (installed; Packagist refresh 🔒) |
| Backup content options | 598–636 | `.updater-backup-options` | `updaterCreateBackup` | `Partials/Guardian/Backup` | — | Free | 🔒 (create disabled) |
| Backup list + delete | 643–663 | `.updater-backup-row`, `.updater-btn-delete` | `updaterReloadBackupList`, `updaterDeleteBackup` | Backup | backupList | Free | ✅ list / 🔒 delete |
| Scheduled mini/full | 666–811 | `.updater-sched-grid`, `.updater-sched-block`, `.updater-sched-toggle`, `.updater-sched-components` | `updaterLoadSchedule`, `updaterToggleScheduleRows`, `updaterRunSchedule` | Backup | scheduleGet | Pro | ✅ read / 🔒 save+run |
| Storage path & notifications | 813–859 | `.updater-sched-row`, `.updater-pkg-checkbox` | `updaterSaveSchedule`, `updaterTestEmail` | Backup | scheduleGet | Pro | ✅ read / 🔒 write |
| Cron explanation | 866–983 | `.updater-sched-cron-note`, `.updater-cron-details`, `.updater-cron-cmd` | — | Backup | — | Pro | 🖥️ (TYPO3 Scheduler adapted) |
| Update runner + dry-run/real | 992–1011 | `.updater-job-card` | `updaterStartJob`, `updaterOpenUpdateModal` | `Partials/Guardian/Update` | — | Pro | 🖥️ modal / 🔒 execution |
| Update modal (modes/snapshot/email) | 1014–1076 | `.updater-modal`, `.updater-radio-row` | `updaterOnUpdateModeChange`, `updaterConfirmStartUpdate` | Update | — | Pro | 🖥️ open/close / 🔒 confirm |
| Job progress/steps/log | 1079–1088 | `.updater-job-steps`, `.updater-job-log`, `.updater-job-archive` | `updaterPollJob`, `updaterRenderJob`, `updaterLoadJobArchive` | Update | jobArchive | Pro | ✅ (idle/empty) |
| Recovery panel filename | 1184–1206 | `.updater-sched-row` | `updaterSaveRecoveryPanelFilename` | `Partials/Guardian/Recovery` | runtimeGet | Pro | ✅ read / 🔒 save |
| Restore explanation | 1208–1223 | `.updater-card` | — | Recovery | — | Pro | 🖥️ |
| Standalone panel info | 1225–1251 | `.updater-card`, `.updater-recovery-url` | `updaterSetStandaloneUrl` | Recovery | panelGet | Pro | 🖥️/🔒 |
| Access-token area | 1253–1279 | `.updater-token-display`, `.updater-token-source-badge` | `updaterLoadPanelConfig`, `updaterRotatePanelToken` | Recovery | panelGet | Pro | ✅ source / 🔒 rotate |
| Pro-license settings | 1096–1124 | `.updater-recovery-row` | status, activate, clear | `Partials/Guardian/Settings` | license status/activate/clear | admin | ✅ |
| Recovery-email settings | 1126–1152 | `.updater-recovery-row` | `updaterSaveRecoveryEmail`, `updaterSendTestRecoveryEmail` | Settings | runtimeGet | — | ✅ read / 🔒 write |
| PHP-CLI settings | 1154–1176 | `.updater-sched-row` | `updaterTestPhpBinary`, `updaterSaveRuntime` | Settings | runtimeGet | — | ✅ read / 🔒 write |

## Deliberate TYPO3 substitutions

| Contao | TYPO3 |
|---|---|
| "Aktuelle Contao-Version" | "Aktuelle TYPO3-Version" (`Typo3Version`) |
| `var/updater/backup` | `var/guardian/backup` |
| `templates/` + `contao/templates/` | `config/` (Site/TypoScript + eigene Templates) |
| `files/` | `fileadmin/` |
| `assets/` | `public/_assets/` |
| `contao-console contao:cron` | `bin/typo3 scheduler:run` (TYPO3 Scheduler) |
| Contao system log (`tl_log`) | TYPO3 logging (`guardian_typo3` component) |
| Contao-Admin-E-Mail | TYPO3 system e-mail (Admin Tools → Settings) |
| Twig `path('route')` | server-built backend AJAX route URLs in the `#guardian-config` island |
| `onclick="…"` inline handlers | `data-action` delegated listener (no inline JS) |

## Localisation follow-up

The German body copy is kept verbatim inline in the Fluid partials (as in the
original German-first product). `locallang.xlf`/`de.locallang.xlf` expose the tab
labels as a localisation seam. Full externalisation of the remaining help copy
(English translations) is tracked here as a follow-up; it does not affect visual
or functional parity.
