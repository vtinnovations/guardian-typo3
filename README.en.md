<!--
  This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.

  @author    V&T Innovations Team
  @license   LGPL-3.0-or-later
  @copyright V&T Innovations 2026 - 2028
-->

# Guardian for TYPO3

*🇩🇪 [Deutsche Version](README.md)*

**Admin cockpit for Composer updates, backups, recovery and extension
management in TYPO3 13.4 and 14** — by [V&T Innovations](https://www.v-t.one).

Guardian is a native TYPO3 backend extension that keeps a TYPO3 installation
updatable and recoverable. From a single admin-only module it drives Composer
updates, full and scheduled backups, backend and standalone recovery, and
complete extension management — each through the same safety pipeline
(mandatory pre-change backup → maintenance mode → change → verification →
automatic rollback on failure).

> **Production status.** Guardian is a fully functional extension. Every section
> below is implemented end-to-end from the backend UI to server-side execution.
> Destructive operations run in detached PHP worker processes, are guarded by
> explicit confirmation, are preceded by a mandatory safety backup, and roll
> back automatically on failure. Guardian originated as a port of the Contao
> Guardian bundle; it is now an independent native TYPO3 extension and is
> documented here as it works today.

## Requirements

- **TYPO3 13.4.9 through 14.x** (`typo3/cms-core: ^13.4.9 || ^14.0`). A single
  package supports both; 13.4.9 is the minimum.
- **PHP 8.2+** (`~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0`). The effective floor is
  whatever the installed TYPO3 core enforces.
- **Composer-managed installation.** Update and extension features operate on
  `composer.json`/`composer.lock` and require Composer mode.
- **`ext-json`** (required). **`ext-zip`** is used to create/read backup
  archives; **`ext-pdo`** backs the pure-PHP database-dump fallback when
  `mysqldump` is unavailable.
- A **writable `var/` directory** — Guardian stores all runtime state under
  `var/guardian/`.

### PHP CLI and Composer configuration

Update and recovery jobs run in a **detached PHP CLI worker**, not in the web
request. Guardian therefore needs, at run time:

- a reachable **PHP CLI binary** — auto-detected, and configurable in
  **Settings → PHP CLI settings** if detection fails;
- a reachable **`composer.phar`** (or Composer binary) in the project;
- the **`vendor/bin/typo3`** console binary (present in any Composer TYPO3
  install).

Backups additionally use `mysqldump`/`mysql` when available (with a pure-PHP PDO
fallback for the dump) and the `ext-zip` archive writer.

### Filesystem permissions

The PHP process (web and CLI) must be able to **read and write `var/guardian/`**
and, for updates/installs, the project's `composer.json`, `composer.lock`,
`vendor/` and `packages/` directories. Guardian creates its private
subdirectories with restrictive permissions and never widens them to world-writable.

## Installation

```bash
composer require vtinnovations/guardian-typo3
```

Then flush TYPO3 caches so the backend module is registered:

```bash
vendor/bin/typo3 cache:flush
```

The **Guardian** module appears under **System** and is visible to **TYPO3
administrators only**. There is no `ext_emconf.php` and no legacy install step —
Composer metadata is authoritative.

## Backend module access

- Registered with `access: admin` (administrators only) and additionally
  asserted in code (`assertAdministrator()`) on every request and AJAX endpoint —
  the access guarantee never depends on routing configuration alone.
- All state-changing endpoints are **POST-only** and carry TYPO3's CSRF route
  token.
- Feature access beyond “administrator” is gated by the active licence tier
  (see the [entitlement matrix](#licence-and-entitlement-matrix)).

## Navigation

The module is a single page with six client-side tabs, in this order:

1. **Dashboard**
2. **Update**
3. **Backup**
4. **Recovery**
5. **Extensions**
6. **Settings**

### Dashboard

- Licence and entitlement summary (None / Free / Pro) with the unlocked-feature
  list and an upgrade/enter-licence call to action.
- System information: detected **TYPO3 version**, **installed-package count**,
  **available-backup count**.
- Current operational status (idle indicator).
- **Pre-update analysis** launcher: a read-only environment check (Composer mode,
  Composer files, PHP version, working-directory writability, PHP CLI, database
  connectivity, disk space, licence, running-job state, backup capability).

### Update

- Detects the **installed TYPO3 version** and performs **online release
  discovery** against TYPO3’s public release feed: the **latest release of the
  current major** and the **next stable major**.
- **Target-version selection**, then a **Composer dry run** that reports the
  **affected packages and extensions** without touching the live project.
- **Run Live** stays disabled until a target is selected and a dry run succeeds.
- A live update runs the full safety pipeline: **mandatory safety backup →
  maintenance mode → `composer update` → TYPO3 extension setup / database schema →
  cache flush → resulting-installation verification**, with **automatic rollback**
  from the safety backup on any failure. A manual **rollback** control is also
  available.
- Live **progress**, step states and streaming logs; a list of **recent update
  jobs**; and **reopening a finished job** to review its final status and logs.

### Backup

- **Manual backups** with per-component selection: the always-included core set
  (`composer.json` + `composer.lock` + database dump), **fileadmin**, **local
  `packages/`**, generated extension assets, and the **`vendor/`** directory.
- **Database dump** via `mysqldump` with a pure-PHP PDO fallback.
- Correct handling of **Composer path-repository symlinks** so local packages are
  captured as real files.
- Each archive carries a **manifest and checksums**; backups are **validated**
  before they are offered for recovery. **Retention** limits are enforced per
  profile.
- **Scheduled backups** (mini/full profiles with frequency, time and weekday/day
  rules) are configured here and executed by the `guardian:backup:run-due`
  console command (see [Deployment](#deployment)).
- **Pre-update safety backups** are created automatically by the Update and
  Extensions pipelines before any change.
- List, inspect details, download and delete backups.

### Recovery

Two independent recovery paths:

- **Backend recovery** (inside TYPO3): backup discovery, a **mandatory preflight
  and dry run**, component selection, staging, **local-package restoration**, a
  **safe vendor rebuild** from `composer.lock` in isolated staging with **atomic
  vendor switching**, **database restoration**, maintenance mode, a **transaction
  journal**, **rollback**, **interrupted-recovery detection and rollback**, and
  **post-recovery verification**.
- **Standalone recovery panel**: a single, self-contained PHP entry point that
  Guardian deploys into the public web root and that restores a Guardian backup
  **even when TYPO3 no longer boots**. It authenticates with the **recovery
  token** (stored hashed, or supplied via the `GUARDIAN_RECOVERY_TOKEN`
  environment variable), is rate-limited, and reuses the same recovery engine as
  the backend. Its filename, deployment and token are managed from Recovery/Settings.
- **Recovery email notifications**: before a live update, Guardian can email the
  recovery URL and access token to a configured address, sent through TYPO3’s
  `MailerInterface`.

### Extensions

Full Composer-based extension management (Pro):

- **Installed extension/package listing** with **classification** (TYPO3 core,
  system extension, third-party extension, local extension, Composer library) and
  **update discovery**.
- **Per-package update**, **enable**, **disable** and **removal**, each with a
  dry run, confirmation, safety backup and rollback.
- **Guardian self-management**: a deferred **self-disable** and a controlled
  **self-removal**, both requiring typed confirmation phrases; Guardian never
  deletes its own package directory implicitly.
- **TER**: search the TYPO3 Extension Repository, show **compatibility
  information** for the running TYPO3 version, and install via a **dry run →
  install** flow.
- **Custom ZIP upload**: uploaded into a **private staging area**, passed through
  **ZIP security inspection** and **extension-metadata detection**, then a
  **dry run → install** flow.
- Local installs register an **exact path-repository version mapping** and
  **Guardian-managed ownership metadata**, so a later **removal deletes the owned
  source directory** (via quarantine) and a previously removed uploaded extension
  can be **re-uploaded and reinstalled** cleanly (orphaned-directory detection).
- Live **progress, structured error reporting and job logs** throughout.

### Licence (System → VTOne Licensing)

- **Licence**: activate, **Update Licence** and remove a licence. These controls
  live in one place — **System → VTOne Licensing**, a shared screen that lists one
  section per installed V-T.ONE product. Guardian's Settings tab links to it and
  holds no licence controls of its own. Use this screen for activation, refresh
  and removal; there is no supported way to install or edit a licence by hand.

  Guardian requires an activated V-T.ONE licence and is sold as **Free** or
  **Pro**. Both tiers require an activated key, and Free and Pro capabilities are
  enforced on the server. Routine entitlement checks are performed locally against
  authenticated, integrity-protected licence data, so a verified licence keeps
  working without network access until it expires. An **expired Pro licence falls
  back to the Free feature set only when the licence itself permits it**;
  otherwise expiry disables the restricted functions.

  A licence authorises a specific set of host names. Guardian grants Free or Pro
  when one of those hosts is also configured in **TYPO3 Site Configuration** — a
  site `base`, a language `base` or a `baseVariants` entry. Several domains may be
  configured; one exact match is enough. `www.example.com` and `example.com` are
  treated as different hosts, and an installation with no site configuration
  cannot be licensed.

### Settings

- **PHP CLI settings**: auto-detect, test and persist the PHP CLI binary path.
- **Recovery email**: configure recipient/sender and **send a test email**
  (through `MailerInterface`).
- **Standalone recovery configuration**: panel filename, deployment and token.

## Licence and entitlement matrix

Access is enforced **server-side** on every endpoint (administrator gate → licence
gate). “Free” means an activated Free licence, or an expired Pro licence that
permits the Free fallback; “Pro” means an active Pro licence.

| Feature | Access |
| --- | --- |
| Manual Backup | **Free and Pro** |
| Scheduled Backup | **Pro only** |
| Update | **Pro only** |
| Extensions | **Pro only** |
| Recovery (backend) | **Pro only** |
| Standalone Recovery (deploy & manage) | **Pro only** |
| Licence activation / Update / removal (Settings) | **Available** (administrator) |
| Pre-update analysis (Dashboard) | **Available** (administrator) |

Effective access per licence state:

| Licence state | Manual Backup | Scheduled Backup | Update | Extensions | Recovery | Standalone Recovery |
| --- | --- | --- | --- | --- | --- | --- |
| No licence | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable |
| Active **Free** | Available | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable |
| Active **Pro** | Available | Available | Available | Available | Available | Available |
| Not yet valid | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable |
| Expired **Pro** that permits the Free fallback | Available (Free fallback) | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable |
| Expired **Pro** without it, or expired **Free** | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable |
| Licence not valid for this installation | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable |

With **no valid licence**, only the Dashboard and Settings are usable (so a
licence can be entered). Status labels used above: **Available**, **Pro only**,
**Free and Pro**, **Not applicable**.

## Security architecture

- **Administrator-only** module and endpoints (`access: admin` + in-code
  assertion); **POST + CSRF token** on every mutation.
- **Path containment**: all Guardian state is confined to `var/guardian/` and
  validated symlink-agnostically; uploads are contained to a private staging
  directory.
- **No shell string execution**: external processes run through `symfony/process`
  with argument arrays — never `exec()`/`shell_exec()`/`system()`/backticks.
- **ZIP-safety inspection** (path traversal, symlink, entry-count/size and
  decompression-bomb checks) on every uploaded archive.
- **Secret redaction**: logs and API responses never expose the full licence key,
  licence authentication material, recovery tokens, transport credentials, DSNs,
  stack traces or absolute installation paths.
- **Licence data** is stored outside the public web root and is validated for
  authenticity and integrity before it is honoured; the standalone recovery
  **token** is not stored in recoverable form.

See [`Documentation/SecurityModel.en.md`](Documentation/SecurityModel.en.md).

### Update, backup and recovery safety

- **Update safety**: mandatory pre-update backup, maintenance mode, isolated
  Composer dry run, verification of the result, and **automatic rollback** from
  the safety backup if any step fails.
  See [`Documentation/UpdateImplementation.en.md`](Documentation/UpdateImplementation.en.md).
- **Backup safety**: manifests + checksums, validation before recovery, retention
  enforcement, and correct symlink/vendor/local-package handling.
- **Recovery safety**: mandatory dry run, atomic vendor switching, a transaction
  journal, interrupted-recovery detection/rollback, and post-recovery
  verification. See [`Documentation/RecoverySafety.en.md`](Documentation/RecoverySafety.en.md)
  and [`Documentation/StandaloneRecoveryPanel.en.md`](Documentation/StandaloneRecoveryPanel.en.md).
- **Extension-installation safety**: private staging, ZIP inspection, dry run,
  managed-ownership metadata, and safe removal/reinstall of managed source
  directories.

## Runtime directories

Guardian keeps **all** state under `var/guardian/`, including: licence data,
runtime configuration, backup schedules, process locks, update jobs and their
logs, created **backups**, recovery staging and the transaction journal,
extension **upload staging** and **quarantine** of removed managed directories,
and the standalone recovery-panel token. This directory is outside the public web
root and must stay that way. Nothing is written outside
`var/guardian/` except the operations the administrator explicitly triggers
(Composer changes, the deployed recovery-panel file in the web root, and restored
project files).

## External V-T.ONE communication

Guardian communicates only with trusted V-T.ONE HTTPS services at
`www.v-t.one`, with transport-layer verification always enabled, and fails safe
if they are unreachable. There are two kinds of outbound traffic:

- **Licence activation and refresh** — performed only when an administrator
  activates or explicitly refreshes a licence from the Guardian interface.
- **Operational signals** — fire-and-forget, never blocking a request or a licence
  decision:
  - once per web invocation, transmitting **only** the product identifier and the
    normalised domain;
  - once per signed-in backend session, when an administrator first opens the
    module, transmitting **only** the normalised domain and the licence key. This
    is server-to-server; the key never reaches the browser or the logs.

Guardian also **receives** authorised, authenticated licence updates initiated by
V-T.ONE. These arrive on a machine-facing endpoint this installation serves; they
are not an address Guardian calls, they are applied only after authentication,
and they are applied atomically or not at all.

No other outbound HTTP is performed except the TYPO3 Extension Repository /
Packagist lookups used by the Extensions tab.

## Logging and secret redaction

Operational output is written to Guardian’s job logs and the TYPO3 system log.
All log lines and AJAX payloads pass through secret redaction before leaving the
server: the full licence key, licence authentication material, recovery tokens,
mail transport DSNs/credentials, and absolute paths are never emitted.

## Deployment

```bash
# 1. Install / update the extension
composer require vtinnovations/guardian-typo3

# 2. Regenerate the autoloader (production)
composer dump-autoload -o

# 3. Register/refresh the module and apply extension setup
vendor/bin/typo3 extension:setup
```

Scheduled backups require an **external trigger** that invokes the console
command periodically — a real cron entry or a TYPO3 Scheduler *“Execute console
command”* task. Guardian does **not** auto-register a Scheduler task.

```cron
*/5 * * * * /usr/bin/php /path/to/project/vendor/bin/typo3 guardian:backup:run-due
```

Registered console commands:

- `guardian:backup:run-due` — run scheduled backups that are currently due (cron/Scheduler).
- `guardian:update:run` — internal detached update/extension worker (spawned by Guardian; not run by hand).
- `guardian:release:check` — verifies this build is fit to distribute (checks the pinned verification keys). Exits non-zero when it is not.

## Cache clearing

```bash
vendor/bin/typo3 cache:flush
```

After deploying updated frontend assets, hard-refresh the backend so the updated
`guardian.js`/`guardian.css` are loaded.

## Testing

```bash
composer install
vendor/bin/phpunit -c phpunit.xml.dist
```

The suite under `Tests/Unit/` covers the CMS-independent logic (licence
interpretation and store schema, path safety, archive validation, schedule math,
lock behaviour, Composer command construction, package classification, job
transitions, managed-ownership and removal). See
[`Documentation/Testing.en.md`](Documentation/Testing.en.md) for the full command set.

## Known limitations

- **Composer mode is required** for Update and Extensions; on a non-Composer
  install those tabs cannot operate.
- **Scheduled backups need an external trigger** (real cron or a TYPO3 Scheduler
  “execute console command” task) — there is no auto-registered Scheduler task.
- **Update/recovery workers need a PHP CLI binary and a reachable `composer.phar`**;
  configure the PHP CLI path in **Settings** if auto-detection fails.
- **Backend recovery runs inside TYPO3.** If TYPO3 no longer boots, use the
  **standalone recovery panel**.
- The unit tests are CMS-independent; full TYPO3 functional coverage depends on
  the target environment’s PHP/Composer/database tooling.

## Licence and copyright

LGPL-3.0-or-later · © 2026–2028 V&T Innovations. See [`LICENSE`](LICENSE).
