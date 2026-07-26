# Guardian for TYPO3

*🇩🇪 [Deutsche Version](README.de.md)*

**Admin update, backup & recovery cockpit for TYPO3 13.4 and 14** — by
[V&T Innovations](https://www.v-t.one).

Guardian is being ported from the Contao 5 bundle of the same name to a native
TYPO3 extension supporting **TYPO3 13.4.9 through 14.x** from a single package.
Its goal is to keep a TYPO3 installation updatable and recoverable: Composer
updates, automatic backups, restore/rollback, and a standalone recovery panel
that works even when TYPO3 no longer boots.

> ⚠️ **Development status — Phase 1 (read-only foundation).**
> This release is a **read-only shell**. It performs **no** backups, updates,
> restores, deletions, migrations, or maintenance changes, and ships **no**
> process execution and **no** recovery panel. Every not-yet-built operation
> fails explicitly rather than pretending to succeed. Do not expect any
> destructive capability yet — see `Documentation/ImplementationRoadmap.md`.

## Requirements

- TYPO3 **13.4.9 through 14.x** (`typo3/cms-core: ^13.4.9 || ^14.0`); TYPO3
  13.4.9 is the minimum supported version. Composer mode is strongly recommended
  (update features require it)
- PHP **8.2 minimum** (`~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0`). The effective
  minimum on a given install is whatever the installed TYPO3 core enforces — TYPO3
  13.4 runs on PHP 8.2+, TYPO3 14 raises the floor higher — but the extension
  itself supports the full range
- A writable `var/` directory (Guardian stores its state under `var/guardian/`)

Later phases will additionally need a PHP CLI binary, `composer.phar`, and the
`mysqldump`/`mysql`/`tar` tools for backup and update features.

## Installation (concept)

```bash
composer require vtinnovations/guardian-typo3
```

Then, in the TYPO3 backend, flush caches so the new backend module is registered.
The **Guardian** module appears under **System** and is visible to
**administrators only**.

> No legacy install steps are required. This is a Composer-based TYPO3 extension
> (13.4/14); there is no `ext_emconf.php` and no Contao-style console bootstrap.
> See `Documentation/CompatibilityStrategy.md` for why Composer metadata alone is
> sufficient for the supported installation mode.

## Directory layout

```
guardian_typo3/
├── composer.json                 typo3-cms-extension, PSR-4 → Classes/
├── Configuration/
│   ├── Services.yaml             DI: ports → adapters
│   ├── Icons.php                 module icon
│   └── Backend/Modules.php       admin-only System module
├── Classes/
│   ├── Domain/                   pure value objects & rules (no TYPO3, no I/O)
│   ├── Application/              use-case services + Contract/ ports
│   ├── Infrastructure/           JSON repos, clock, flock, paths, refusing executor
│   ├── Typo3/                    TYPO3 adapters (environment, auth, logging, scheduler)
│   └── Controller/Backend/       backend module controller
├── Resources/
│   ├── Private/{Layouts,Templates,Partials,Language}/   Fluid + XLF
│   └── Public/{Css,JavaScript,Icons}/                   external assets
├── Tests/Unit/                   CMS-independent PHPUnit tests
└── Documentation/                audit, feature matrix, architecture, security, roadmap
```

Guardian's runtime state lives under `var/guardian/` (runtime config, license
cache, schedule, locks, and — in later phases — jobs, logs and backups).

## Security

- The backend module is **administrator-only** (`access: admin`), reinforced by an
  in-code admin assertion.
- Phase 1 has **no write endpoints** and executes **no** external process.
- All path handling is contained to `var/guardian/` and validated
  symlink-agnostically; archive-safety and secret-redaction rules from the Contao
  original are ported and unit-tested.
- The standalone recovery panel is treated as a **separate, later,
  security-critical deliverable** and is deliberately **not** part of this phase.

See `Documentation/SecurityModel.md` for the full model.

## Interface

The backend module is a **faithful port of the original Guardian interface**: one
page with the original five tabs — **Dashboard, Update, Backup, Recovery,
Einstellungen** — switched client-side, with the original `.updater-*` card,
table, badge, modal and job-runner styling and the orange Guardian branding, in
both light and dark backend modes. Scheduled backups live inside **Backup**; the
Pro licence, recovery e-mail and PHP-CLI settings live inside **Einstellungen**.
See `Documentation/ContaoUiParityMatrix.md` and
`Documentation/VisualParityChecklist.md`.

## Current functionality

- **Live (read-only):** license status, installed-package list, backup list,
  schedule/runtime config display, recovery-token source, and the pre-update
  analysis — all served by CSRF-protected, admin-only backend AJAX endpoints.
- **Disabled with an explicit reason:** every destructive control (create/delete
  backup, run/schedule updates, activate/clear licence, save runtime settings,
  rotate token, send e-mails). These render `disabled` with an inline note naming
  the backend phase that will enable them — nothing fakes success.
- No outbound HTTP, no process execution, no scheduled execution yet.

## Development

```bash
composer install
vendor/bin/phpunit -c phpunit.xml.dist   # CMS-independent unit tests
```

> The unit tests in `Tests/Unit/` cover the CMS-independent logic (config
> validation, path safety, schedule math, lock behaviour, license interpretation,
> archive validation, command construction, job transitions). They have **not**
> been executed in the environment where this scaffold was generated (no
> PHP/Composer runtime was available there). See `Documentation/Testing.md` for
> the full command set and the TYPO3 13.4 / 14 CI matrix.

## Planned CLI (not yet implemented)

Later phases will introduce a TYPO3 console worker command (e.g. the background
job runner) and Scheduler integration for backups. These are **planned** and do
not exist in Phase 1.

## License

LGPL-3.0-or-later · © V&T Innovations
