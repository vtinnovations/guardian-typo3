# Implementation Roadmap

Guardian is built in small, individually reviewable phases. Each phase adds one
coherent capability behind its security controls and leaves the product in a
shippable, non-broken state. Destructive capabilities appear only once their
safety controls and tests exist.

**Phase 1 (this scaffold) is complete.** It delivers the read-only foundation:
layered architecture, ports, pure domain logic, safe foundational services, the
admin-only backend shell, and the test structure.

---

## Phase 1 — Read-only foundation & environment inspection ✅

- **Scope**: extension scaffold; Domain value objects & pure services; ports;
  safe infrastructure (paths, clock, lock, JSON repos, refusing executor); TYPO3
  read adapters (environment, authorization, logging, scheduler-detect); admin-only
  backend module with 7 read-only sections; unit tests; documentation.
- **Dependencies**: none.
- **Risks**: backend-module template resolution and DI wiring cannot be
  runtime-verified in this environment (no PHP/TYPO3). Mitigated by using only the
  shared TYPO3 13.4 / 14 backend-module API and confining TYPO3 use to adapters/the
  controller edge. See `CompatibilityStrategy.md` for the version-support policy.
- **Acceptance**: extension installs; module appears under System for admins;
  every section renders; non-destructive; no `exec`/write endpoints.
- **Tests**: unit tests for config validation, path safety, schedule math, lock,
  license interpretation, archive validation, command construction, job transitions.
- **Manual server tests**: install via Composer; open the module as admin; confirm
  each section renders and Settings/License/Schedule show read-only data.
- **Excluded**: all destructive actions; recovery panel; outbound HTTP; writes.

## Phase 2 — License integration & guarded settings write

- **Scope**: license verifier port + HTTP adapter; activate/clear with server
  verification and grace caching; guarded runtime-config write (PHP binary etc.).
- **Dependencies**: Phase 1.
- **Risks**: outbound HTTP to license server; first write endpoints.
- **Acceptance**: admin can activate a key and see Pro unlock server-side; config
  writes are admin+token gated and validated by `RuntimeConfiguration`.
- **Tests**: verifier response normalisation; gate behaviour; config write validation.
- **Manual**: activate a real key; verify grace behaviour offline.
- **Excluded**: anything that runs a process.

## Phase 3 — Manual backup

- **Scope**: real, shell-free command executor; TYPO3-layout backup (DB dump +
  `vendor/`, `config/`, `fileadmin/`, selected dirs); manifest.
- **Dependencies**: Phases 1-2; executor.
- **Risks**: first process execution; DB credential handling.
- **Acceptance**: admin creates a backup; artifacts + manifest written under
  `<var>/guardian/backup/`; secrets never in argv/logs.
- **Tests**: manifest shape; component selection; executor argv/env.
- **Manual**: create backup on a real host (with and without `mysqldump`/`tar`).

## Phase 4 — Backup listing, retention & deletion

- **Scope**: backup repository; retention policy; guarded delete.
- **Dependencies**: Phase 3.
- **Acceptance**: list shows backups; retention prunes correctly; delete is
  admin+token gated and path-contained.
- **Tests**: retention selection; delete path validation.

## Phase 5 — Scheduler integration

- **Scope**: TYPO3 Scheduler task (or crontab console command) invoking the
  due-evaluation + backup run under a lock; schedule config write.
- **Dependencies**: Phases 3-4; `ScheduleEvaluator` (done), `FlockLock` (done).
- **Acceptance**: due backups run once per slot; concurrent runs blocked by lock.
- **Tests**: forecast integration; lock contention.
- **Manual**: register the task; observe a scheduled run.

## Phase 6 — Package & update analysis

- **Scope**: installed/outdated inspection (Packagist behind HTTP port);
  pre-update environment checks; optional dashboard badge.
- **Dependencies**: Phase 1; executor for platform checks.
- **Acceptance**: outdated packages listed; checks flagged; no changes made.

## Phase 7 — Job persistence & background worker

- **Scope**: job repository; TYPO3 console worker command; detached spawn; stale
  detection; live log streaming.
- **Dependencies**: `Job` VO (done); executor.
- **Risks**: worker-spawn reliability on restricted hosts.
- **Acceptance**: a queued job runs to completion in a detached worker; UI polls.

## Phase 8 — Composer update pipeline

- **Scope**: full/patch/selective update steps; cache flush + schema migration via
  ports; pre-update snapshot; maintenance around the run.
- **Dependencies**: Phases 3, 7; `CacheManagerInterface`, `DatabaseSchemaUpdaterInterface`,
  `MaintenanceModeInterface` adapters.
- **Risks**: **destructive**; must be failure-safe (maintenance always cleared).
- **Acceptance**: successful update path; failed update rolls back cleanly.

## Phase 9 — Restore & rollback

- **Scope**: restore pipeline (files + DB) with `ArchiveEntryValidator` enforced;
  one-click rollback to pre-update snapshot; consistency analysis.
- **Dependencies**: Phases 3, 8.
- **Risks**: **most destructive**; requires explicit confirmation.
- **Acceptance**: restore verified against a known backup; traversal rejected.

## Phase 10 — Notifications

- **Scope**: `MailerInterface` adapter; backup/recovery emails (rate-limited).
- **Dependencies**: Phases 3-5.
- **Acceptance**: success/failure emails sent when configured; failures swallowed
  safely.

## Phase 11 — Standalone recovery panel

- **Scope**: separate, hardened, framework-free panel; opt-in non-boot deployment;
  token + brute-force + same-origin; configurable filename.
- **Dependencies**: Phases 3, 9.
- **Risks**: highest attack surface; full security review required before release.
- **Acceptance**: panel restores when TYPO3 is down; all controls verified.

## Phase 12 — Security hardening & release preparation

- **Scope**: end-to-end security review; permission audit; docs; packaging;
  functional/acceptance tests.
- **Dependencies**: all prior.
- **Acceptance**: external review sign-off; TER/Composer release metadata.

---

## Test commands (run in a real PHP/TYPO3 environment)

These have **not** been executed here (no PHP/Composer/TYPO3 runtime available).

```bash
# Install dependencies (in a TYPO3 project requiring this extension, or standalone)
composer install

# Run the CMS-independent unit tests
vendor/bin/phpunit -c phpunit.xml.dist

# (Later phases) TYPO3 functional tests via typo3/testing-framework
vendor/bin/phpunit -c Build/FunctionalTests.xml
```
