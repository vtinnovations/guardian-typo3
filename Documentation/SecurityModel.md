# Security Model — Guardian for TYPO3

Guardian is, by nature, a high-privilege tool: fully built it can run Composer,
dump and restore databases, delete files and toggle maintenance mode. The
security model is therefore central, not an afterthought. This document states
the required controls, what Phase 1 already enforces, and what each later phase
must implement before its feature is exposed.

Phase 1 ships **no destructive capability at all**, which is itself the strongest
possible control for this stage: there is no write endpoint, no process
execution, and no recovery panel.

## 1. Authorization

- **TYPO3 backend administrator only.** The module is registered with
  `access: 'admin'` in `Configuration/Backend/Modules.php` — TYPO3 refuses the
  route to non-admins natively.
- **Defence in depth.** `GuardianModuleController::handleRequest` additionally
  calls `BackendAuthorizationInterface::assertAdministrator()`, so the guarantee
  does not rely solely on routing configuration (mirrors the audited Contao
  `BackendAuthChecker`, which existed precisely because backend routes were not
  admin-restricted by default).
- **Every future write/AJAX route must repeat both checks.** No endpoint may
  assume the module gate is sufficient.
- **Cross-version note (13.4 / 14).** The `access: 'admin'` module gate,
  `BackendUserAuthentication::isAdmin()`, and backend-user retrieval behave
  identically on TYPO3 13.4.9 and 14. The admin check is isolated in the
  `Typo3\Authorization\BackendUserAuthorization` adapter; no domain or application
  service touches `$GLOBALS['BE_USER']`, so the authorization model is
  version-neutral and requires no version branching.

## 2. Request-token / CSRF handling

- The Contao original used a same-origin (Origin/Referer) check. TYPO3 provides a
  stronger, built-in backend **request token** mechanism (`RequestToken` /
  `FormProtection`), available and equivalent in 13.4 and 14.
- Every state-changing backend route validates
  a TYPO3 backend request token (`FormProtection`/`RequestToken`), in addition to
  admin authorization. GET routes remain side-effect free.
- The license activation and removal routes use TYPO3-generated backend AJAX
  URLs carrying the request token and also assert administrator authorization.

## 3. Protection of write endpoints

- Apart from the admin-only, CSRF-protected license activation/removal slice, the Settings/Schedule
  sections render current values read-only; no form posts back.
- When write paths arrive they must: (a) be POST/PUT/DELETE only, (b) require
  admin, (c) validate the request token, (d) validate/normalise all input through
  domain value objects before touching disk.

## 4. Command injection & process invocation

- **Shell-free by construction.** `CommandRequest` can only hold an argv array;
  there is no way to express a shell command string. It rejects NUL bytes and
  empty binaries. Future executors MUST pass this argv to Symfony Process without
  `Process::fromShellCommandline` and without `/bin/sh -c`.
- **No `exec()`/`shell_exec()`/`system()`/backticks in Phase 1.** The only executor
  shipped, `UnavailableCommandExecutor`, throws `NotImplementedException`.
- **Secrets via environment, never argv.** The Contao pattern of passing DB
  passwords through `MYSQL_PWD` is preserved in `CommandRequest::withEnv()`, which
  keeps the secret off the (display-only) `describe()` rendering and out of any
  process listing.
- **PHP/Composer binary validation** (later phase): the configured PHP binary must
  be validated as a real CLI binary (reject `-fpm`/`-cgi`), and Composer must
  always be driven as `<php> composer.phar` to keep platform-requirement checks
  consistent with the runtime SAPI.

## 5. Filesystem containment, symlinks, archive traversal

- **Single owned working directory.** `GuardianPaths` resolves exactly one base
  (`<var>/guardian`) and every derived path goes through `PathNormalizer` +
  `isContained()`; a name that would escape the directory throws. No caller
  concatenates paths ad hoc (the Contao original did, everywhere).
- **Symlink-agnostic reasoning.** `PathNormalizer` resolves `.`/`..` lexically
  and never calls `realpath()` for the security decision, so a symlink cannot
  tunnel a "contained" path outside the base — the same defensive stance the
  Contao `ScheduleConfig`/`RestoreManager` took.
- **Archive traversal.** `ArchiveEntryValidator` rejects absolute paths (POSIX and
  Windows/UNC) and any `..` segment. Restore (a later phase) must validate the
  full archive listing with it *before* extraction and abort on any unsafe entry.

## 6. Secrets & token storage

- **Runtime config & license cache** live under `<var>/guardian/` with restrictive
  permissions (`0640` files, `0750` dirs) and atomic temp-file+rename writes.
- **Log redaction.** `Typo3SystemLogger` centrally redacts passwords, tokens,
  bearer credentials and DSN-embedded passwords before anything is logged (ported
  from the Contao `JobLog` rules).
- **License API secret** is a low-value shared product key; treat as rotatable and
  never log it.

## 7. License cache integrity

- Entitlement is decided server-side by `LicenseGuard` over the cached
  `LicenseState`; UI locks are convenience only.
- The grace window (default 7 days) is bounded and expiry is always enforced, so a
  stale cache cannot indefinitely unlock Pro features.

## 8. Recovery panel — explicitly out of scope for Phase 1

The standalone recovery panel is the single most sensitive component: a
framework-free PHP file in the webroot that can restore backups and import
databases. It is **not** copied into this project. It is scheduled as a separate,
later, security-critical deliverable that must, at minimum, provide:

- opt-in, non-boot-time deployment with easy removal;
- header-only auth (Basic/Bearer) with timing-safe comparison;
- per-IP brute-force lockout;
- same-origin enforcement on state-changing requests;
- configurable, hard-to-guess filename;
- a strong recommendation to add webserver-level IP/HTTP-auth protection.

## 9. Job concurrency & failure-safe maintenance

- **Locking.** `FlockLock` provides non-blocking mutual exclusion with stale-lock
  reclaim so a crashed run cannot wedge the system; the job/backup pipelines
  (later) acquire a named lock via `LockFactoryInterface`.
- **Failure-safe maintenance** (later phase): the `MaintenanceModeInterface` design
  requires maintenance to be turned back OFF even after a failed operation — the
  Contao `JobRunner` treated `maintenance_off` as a cleanup step that always runs;
  the TYPO3 pipeline must preserve this.

## 10. Backup exposure & restore confirmation

- Backups must never be stored web-accessibly or where a deploy wipes them; the
  storage-path validator (ported from Contao `ScheduleConfig`) forbids
  `public/`, `vendor/`, `fileadmin/`-style locations and system directories
  (later phase).
- Restore is destructive and must require an explicit, admin-only confirmation
  step and a pre-restore snapshot option (later phase).

## Phase-1 enforcement summary

| Control | Phase-1 state |
|---|---|
| Admin-only module | ✅ enforced (`access: admin` + code assertion) |
| No write endpoints | ✅ none exist |
| No process execution | ✅ executor refuses; no `exec`/`shell_exec`/`system`/backticks |
| Path containment | ✅ `GuardianPaths` + `PathNormalizer` |
| Archive-safety rule | ✅ `ArchiveEntryValidator` (ready for restore phase) |
| Secret redaction | ✅ in logging adapter |
| Server-side license gate | ✅ `LicenseGuard` |
| CSRF token on writes | ⏳ n/a (no writes yet); required from Phase 2 |
| Recovery panel | ⏳ deliberately absent |
