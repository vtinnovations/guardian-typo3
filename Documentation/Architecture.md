# Architecture — Guardian for TYPO3

Guardian is built as a layered, ports-and-adapters architecture. The central
design rule, learned from auditing the Contao original, is:

> **Core backup, job, schedule and licensing logic must never depend on TYPO3
> globals, the filesystem, the clock, or an external process.** Everything
> environment-specific is reached through an interface (a *port*) implemented by
> a thin *adapter*.

This keeps the valuable, hard-won business rules (schedule math, entitlement
windows, archive-safety, job lifecycle) pure and unit-testable, and confines the
risky, framework- and OS-specific parts to small, individually reviewable seams.

## Layers

```
Classes/
├── Domain/            Pure business rules & value objects. No I/O, no TYPO3,
│                      no clock, no globals. Fully unit-testable.
├── Application/       Use-case services + ports (Contract/). Orchestrate the
│                      domain; depend only on interfaces, never on adapters.
├── Infrastructure/    Framework-neutral adapters: JSON repositories, flock,
│                      clock, paths, the (Phase-1 refusing) command executor.
├── Typo3/             TYPO3-specific adapters. The ONLY code that touches
│                      Environment, BE_USER, TYPO3 logging, Scheduler, Mailer.
└── Controller/Backend/ Backend edge: turns a request into service calls and a
                        Fluid response. No business logic.
```

Dependency direction is strictly inward: `Controller → Application → Domain`,
with `Infrastructure` and `Typo3` implementing Application ports and wired only
in `Configuration/Services.yaml`. The Domain depends on nothing.

## The ports (CMS/OS seams)

Defined in `Classes/Application/Contract/` (plus `Domain/Clock/ClockInterface`).
Names refine the ones requested in the brief; the mapping is:

| Requested interface | This project | Phase-1 adapter |
|---|---|---|
| `BackendAuthorizationInterface` | `BackendAuthorizationInterface` | `Typo3\Authorization\BackendUserAuthorization` |
| `CacheManagerInterface` | `CacheManagerInterface` | *(interface only — update phase)* |
| `MaintenanceModeInterface` | `MaintenanceModeInterface` | *(interface only — restore phase)* |
| `DatabaseSchemaUpdaterInterface` | `DatabaseSchemaUpdaterInterface` | *(interface only — update phase)* |
| `SystemLoggerInterface` | `SystemLoggerInterface` | `Typo3\Logging\Typo3SystemLogger` |
| `CommandExecutorInterface` | `CommandExecutorInterface` | `Infrastructure\Process\UnavailableCommandExecutor` (refuses) |
| `ProjectEnvironmentInterface` | `ProjectEnvironmentInterface` | `Typo3\Environment\Typo3ProjectEnvironment` |
| `MailerInterface` | `MailerInterface` | *(interface only — notifications phase)* |
| `SchedulerIntegrationInterface` | `SchedulerIntegrationInterface` | `Typo3\Scheduler\Typo3SchedulerIntegration` |

Supporting ports added for a clean Phase 1: `WorkingDirectoryProviderInterface`,
`LockInterface` / `LockFactoryInterface`, `RuntimeConfigurationRepositoryInterface`,
`ScheduleRepositoryInterface`, and the ports backing entitlement state.

Interfaces with no Phase-1 adapter are intentional: the seam is fixed now so the
later destructive pipelines depend on the abstraction, but no destructive
implementation ships yet. The command executor DOES ship an adapter — one that
throws `NotImplementedException` rather than ever silently succeeding.

## Domain contents

Pure value objects and services, all with `declare(strict_types=1)` and, where
they carry state, immutable:

- **Configuration**: `RuntimeConfiguration` (validated, immutable).
- **Schedule**: `ScheduleFrequency`, `BackupSchedule`, `ScheduleRun`, and the pure
  `ScheduleEvaluator` (ported near-verbatim from Contao; time passed in).
- **Entitlement**: value objects describing the licence tier, the host binding
  and the validity rules. Entitlement internals are intentionally not detailed in
  this document.
- **Job**: `JobStatus`/`JobType`/`UpdateMode` enums and the immutable `Job` with a
  guarded state machine (`JobStatus::canTransitionTo`).
- **Process**: `CommandRequest` (argv-only, shell-impossible) and `CommandResult`.
- **Archive**: `ArchiveEntryValidator` (zip/tar-slip guard).
- **Filesystem**: `PathNormalizer` (lexical, symlink-agnostic containment).
- **Clock**: `ClockInterface`.
- **Exception**: `GuardianException`, `NotImplementedException`, `InvalidConfigurationException`.

## Application contents

- `Configuration\RuntimeConfigurationService` — read config.
- `Environment\EnvironmentInspector` — build `EnvironmentCapabilities` (no exec).
- Entitlement services — activation, refresh, removal, evaluation, and the gate
  asserted at each protected feature boundary. These are distributed through the
  layers above rather than concentrated in one place, and are not enumerated
  here.
- `Schedule\ScheduleForecastService` + `ScheduleForecast` — read-only "due/next".
- `Dashboard\DashboardService` — aggregate read model for the module.

## Backend UI

One backend module (`guardian`, under *System*, `access: admin`). The controller
`Controller\Backend\GuardianModuleController::handleRequest` validates an `action`
query parameter against a fixed allowlist and renders one Fluid template per
section. The 176 KB monolithic Contao Twig template is replaced by:

```
Resources/Private/
├── Templates/Guardian/Index.html   the product module: shell + tab panels
├── Partials/Guardian/              Dashboard, Update, Backup, Recovery,
│                                   Extensions, Settings, Tabs
Resources/Public/
├── Css/guardian.css                theme-aware, scoped
└── JavaScript/guardian.js          the product module's script
```

The shared V-T.ONE licence screen has its own template, partial and script
alongside these.

No inline JavaScript. Entitlement state is rendered by the server in both places,
so neither screen depends on a request completing to show what is stored.

Licence controls exist **only** on the shared screen under *System → VTOne
Licensing*. The product module has no licence panel, no licence endpoints in its
endpoint map and no licence code in its script; its Settings tab links to the
shared screen.

## What stays conceptually similar vs. what is redesigned

**Conceptually similar (ported/adapted):**
- `ScheduleEvaluator` — logic essentially unchanged.
- `BackupLock` → `FlockLock` — same flock + stale-reclaim behaviour.
- Runtime-config validation, job lifecycle, archive safety, path containment and
  secret redaction — rules preserved, repackaged.

**Fully redesigned:**
- Backend UI (Twig monolith → Fluid + external assets).
- Authorization & audit logging (Contao Security/`tl_log` → ports + TYPO3 adapters).
- Job worker & command execution (Symfony Process/`exec`/`shell_exec` scattered
  across steps → single `CommandExecutorInterface` seam, shell-free `CommandRequest`).
- Backup/restore (Contao/Symfony tree + `contao-console` → TYPO3 layout + core APIs).
- Cache/maintenance/migration (`contao-console` calls → dedicated ports).
- Scheduling trigger (Contao Cron hook → TYPO3 Scheduler/crontab).
- Recovery panel (rebuilt as a separate, later, security-reviewed deliverable).

## Wiring

`Configuration/Services.yaml` uses autowiring/autoconfiguration, excludes the
pure `Domain/` namespace (re-registering only its three dependency-free services),
makes the module controller public, and binds every port to its Phase-1 adapter.

## TYPO3 13.4 / 14 compatibility

The extension supports **TYPO3 13.4.9 through 14.x from one shared codebase**.
The ports-and-adapters design makes this cheap: all TYPO3 API usage is confined to
the `Typo3/` adapters and the single backend controller, and every TYPO3 API used
there (the array-based backend-module registration, `ModuleTemplateFactory`,
`ModuleTemplate::renderResponse()`, `UriBuilder`, `Environment`, backend-user
globals, `Icons.php`, Symfony DI, and the core Fluid ViewHelpers) is part of the
identical, stable subset available in both 13.4 and 14. No runtime version checks
and no version-specific adapters are required in Phase 1. See
`CompatibilityStrategy.md` and `CompatibilityAudit.md` for the full analysis.
