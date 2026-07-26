# Visual Parity Checklist

Static DOM/CSS comparison between the authoritative Contao template
(`templates/backend/vtinnovations_guardian.html.twig`) and the ported TYPO3
Fluid templates. "JS-injected" marks blocks that the ORIGINAL also generated at
runtime via JavaScript into a container div — the port keeps that behaviour, so
the block lives in `guardian.js` (with its CSS in `guardian.css`), not in static
markup.

## Per-section parity

| Section | Original CSS classes | TYPO3 partial | Original controls | TYPO3 controls | Interaction | Known differences |
|---|---|---|---|---|---|---|
| Tabs | `.updater-tabs/.updater-tab-btn/.updater-tab-content` | `Tabs` | 5 tab buttons, active underline, locked 🔒 | identical, `data-action="tab"` | client tab switch + hash | none |
| Dashboard · plan | `.updater-plan-badge/.updater-plan-card/.updater-plan-features` | `Dashboard` | badge, tagline, feature list, upgrade btn | identical (server-rendered first paint + JS refresh) | `renderPlan` | none |
| Dashboard · stats | `.updater-grid/.updater-stat` | `Dashboard` | 3 stat tiles | identical | server-rendered | Contao→TYPO3 version label |
| Dashboard · status | `.updater-status-badge` | `Dashboard` | status badge + message | identical (idle) | `loadJobStatus` | idle only (no runner yet) |
| Dashboard · analysis | `.updater-check-row/.updater-result-summary` | `Dashboard` (JS-injected) | run button + check rows | identical | `runAnalysis` → `analyse` | none (read-only checks) |
| Dashboard · packages | `.updater-pkg-controls/.updater-pkg-table/.updater-pkg-tag` | `Dashboard` (table JS-injected) | load, filter, only-updates, table | load/filter identical; "Updates prüfen" disabled | `loadPackages`/`renderPackages` | Packagist refresh disabled (later phase) |
| Backup · options | `.updater-backup-options` | `Backup` | 5 component checkboxes | identical (disabled) | — | dirs mapped to TYPO3; create disabled |
| Backup · list | `.updater-backup-row/.updater-btn-delete` | `Backup` | rows + delete | list identical; delete disabled | server-rendered + `reloadBackupList` | none |
| Backup · schedule | `.updater-sched-grid/.updater-sched-block/.updater-sched-toggle/.updater-sched-components` | `Backup` | mini/full config, run-now | identical (inputs disabled) | `loadSchedule`/`toggleScheduleRows` | save/run disabled |
| Backup · notify/storage | `.updater-sched-row` | `Backup` | storage path, emails, toggles, test | identical (disabled) | — | test disabled |
| Backup · cron help | `.updater-sched-cron-note/.updater-cron-details/.updater-cron-cmd` | `Backup` | `<details>` guides | identical | native `<details>` | Contao cron → TYPO3 Scheduler commands |
| Update · runner | `.updater-job-card/.updater-job-steps/.updater-job-log` | `Update` (card JS-injected) | dry-run, real, progress, log, history | modal viewable; execution disabled | `loadJobStatus`/`loadJobArchive` | job runner disabled |
| Update · modal | `.updater-modal/.updater-radio-row` | `Update` | modes, snapshot, email | identical; open/close work; confirm disabled | `openUpdateModal`/`onUpdateModeChange` | confirm disabled |
| Recovery · filename | `.updater-sched-row` | `Recovery` | filename + save | read-only; save disabled | `loadRuntime` | none |
| Recovery · restore/panel | `.updater-card/.updater-recovery-url` | `Recovery` | explanations, standalone URL | identical | `setStandaloneUrl` | panel ships later |
| Recovery · token | `.updater-token-display/.updater-token-source-badge` | `Recovery` (JS-injected) | token, source, rotate | source shown; token/rotate disabled | `loadPanelConfig` | never reveals/generates token |
| Settings · license | `.updater-recovery-row` | `Settings` | key input, activate, clear | read-only status; write disabled | `loadLicenseStatus`/`renderLicenseState` | activate/clear disabled |
| Settings · recovery email | `.updater-recovery-row` | `Settings` | recipient/sender, save, test | read-only; write disabled | `loadRuntime` | disabled |
| Settings · PHP CLI | `.updater-sched-row` | `Settings` | binary, test, save | read-only; write disabled | `loadRuntime` | disabled |
| Upgrade dialog | `.updater-upgrade-modal` | `Index` | modal on locked-tab click | identical | `openUpgradeModal` | none |

## Block presence audit (every major `.updater-*` block accounted for)

Static markup blocks — present in a Fluid partial:
`updater-tabs`, `updater-tab-btn`, `updater-tab-content`, `updater-plan-badge`,
`updater-plan-features`, `updater-grid`, `updater-stat`, `updater-status-badge`,
`updater-pkg-controls`, `updater-backup-options`, `updater-backup-row`,
`updater-btn-delete`, `updater-sched-grid`, `updater-sched-block`,
`updater-sched-toggle`, `updater-sched-components`, `updater-sched-cron-note`,
`updater-cron-details`, `updater-cron-cmd`, `updater-modal`, `updater-radio-row`,
`updater-recovery-row`, `updater-upgrade-modal`.

JS-injected blocks (same as the original) — CSS present, built at runtime into
their container divs:
`updater-check-row` (→ `#updaterAnalysisResult`), `updater-pkg-table`
(→ `#updaterPkgResult`), `updater-job-card`/`updater-job-steps`/`updater-job-log`
(→ `#updaterJobActive`), `updater-token-display`/`updater-token-source-badge`
(→ `#updaterPanelTokenArea`).

**Result: no major `.updater-*` block from the original is unaccounted for.**

## Known unavoidable differences

- Outer chrome is native TYPO3 (ModuleTemplate + document header) instead of
  Contao's `be_main` — required and intended.
- Inline `onclick` handlers are replaced by a single `data-action` delegated
  listener (no inline JS); behaviour is unchanged.
- Endpoint URLs come from a Fluid `#guardian-config` JSON island (CSRF-tokenised
  backend AJAX routes) rather than Contao's Twig `path()`.
- Dark mode keys on TYPO3's `data-color-scheme` attribute + `prefers-color-scheme`
  instead of Contao's `html[data-color-scheme]`; the palette values are identical.
- Destructive controls are `disabled` with an inline reason until their backend
  phases ship (see `ContaoUiParityMatrix.md`).

## Runtime visual checks still required (cannot run here)

Install on TYPO3 13.4.9 and 14.x and verify: no jumbo logo; the five tabs render
and switch; cards/tables/badges/modals show the orange Guardian styling; light
and dark backend modes both read correctly; the CSS/JS assets load once; the
package/analysis/license/schedule/runtime read loaders populate.
