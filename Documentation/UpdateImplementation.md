# Update Implementation

Guardian's Update tab performs a real, background Composer update of a Composer-mode
TYPO3 13.4/14 project, with a mandatory safety backup, maintenance mode, database
schema update, cache flush, verification and rollback — reusing Guardian's existing
Backup, Recovery, Job, Lock, License, Logging, Runtime-Configuration and
Notification subsystems. There is **one** update workflow and **no** duplicate
restore engine.

## Flow

```
Backend (AJAX, admin + Pro)                 CLI worker (guardian:update:run <id>)
──────────────────────────                  ───────────────────────────────────
analyse ─ PreUpdateAnalysis (read-only)
updateCheck ─ composer outdated (JSON)
updateDryRun ─┐                             UpdateJobRunner:
updateStart ──┴─ UpdateService.create()      1 safety_backup   (BackupService)
   → validate + store job (queued)           2 maintenance_on  (MaintenanceMode)
   → reset log                               3 composer         (Symfony Process, argv)
   → spawn detached worker ───────────────▶  4 database_schema  (typo3 database:updateschema)
updateJobStatus / updateJobLog (poll)        5 cache_clear      (typo3 cache:flush)
updateJobs / updateJobDetails                6 verify           (composer.json/lock, autoload, version)
updateRollback ─ RestoreService              7 maintenance_off  (restore prior state)
```

The browser only *starts* a job and polls; a long update never runs inside a web
request.

## Update modes and exact Composer commands

Composer is always invoked as `<php-cli> <composer.phar> …` (never a shell
wrapper) so its platform checks match the site runtime. Baseline flags `[B]` =
`--no-interaction --no-progress --no-scripts` (post-update scripts are replaced by
the explicit schema + cache steps).

| Mode | Command | Rationale |
| --- | --- | --- |
| **Full** | `composer update [B] --with-all-dependencies` | Update everything allowed by `composer.json`, including transitive deps. |
| **Conservative** | `composer update [B] --prefer-stable` | **No** `--with-all-dependencies`, so Composer avoids moving transitive dependencies — genuinely minimal movement, not a relabelled full update. |
| **Selective** | `composer update <pkg…> [B] --with-dependencies` | Only the chosen (server-validated) packages plus their own dependencies. |
| **Dry run** | the selected mode's command **+ `--dry-run`** | Resolves and reports planned changes; touches nothing. |

`--ignore-platform-req=ext-*` flags are added only for extensions the project
requires but the CLI runtime lacks, and each flag is re-validated against
`^--ignore-platform-req=ext-[a-z0-9_]+$` before use. Command construction lives in
the pure, unit-tested `ComposerCommandFactory`; package names are validated by
`PackageName` (Composer syntax, no leading dash) so browser input can never inject
a flag or shell fragment. Every command is an argv array executed with **no
shell** by `SymfonyProcessCommandExecutor`.

## Online update check

`PackageUpdateChecker` runs `composer outdated --direct --no-interaction
--format=json`, merges the result onto `vendor/composer/installed.json`, and
classifies each package with a language-neutral `PackageStatus`
(`current`, `patch_available`, `minor_available`, `major_available`, `abandoned`,
`unknown`, `error`). It is read-only. Failures are classified
(`network_error`, `auth_error`, `repository_error`, `resolution_error`,
`composer_unavailable`, …) and never expose credentials or tokens.

## Safety backup (mandatory)

Step 1 reuses `BackupService::create()` to snapshot composer files + database +
configuration + local packages + templates (+ `vendor/` when selected). If it
fails, the runner aborts **before** enabling maintenance or running Composer. The
snapshot ID is stored on the job for rollback.

## Maintenance, schema, cache (TYPO3 13.4 + 14)

- Maintenance uses the shared `MaintenanceModeInterface`; the previous state is
  detected and restored afterwards. On failure it is kept ON during rollback.
- Schema: `vendor/bin/typo3 database:updateschema "*.add,*.change" --no-interaction`
  — additive/safe changes only; destructive changes are never auto-applied.
- Cache: `vendor/bin/typo3 cache:flush`. Both commands are stable on 13.4 and 14;
  `Typo3ConsoleCommands` is the single adapter to change if that ever differs.
  Cache-clear failure is a **warning**, not a hard failure.

## Verification, failure handling, rollback

Verification checks `composer.json`/`composer.lock` are valid JSON,
`vendor/autoload.php` exists and the TYPO3 version is detectable. On any failure
after Composer may have changed the tree, the runner keeps maintenance ON and
calls the **shared** `RestoreService` to roll back from the safety snapshot
(`createSnapshot=false`), then restores the previous maintenance state only if
rollback succeeded. If `vendor/` was not in the snapshot, the log explains a
controlled `composer install` may be needed to match the restored lock file.
Failures carry stable codes (`start_failed`, `not_confirmed`, `rollback_failed`,
plus the online-check error codes).

## Jobs, progress, logs

`UpdateJobStore` persists a single active job (`var/guardian/update/job.json`) and
an archive (`var/guardian/update/jobs/<id>.json`). `UpdateJobLog` is an
append-only, offset-readable JSON-line log with the audited secret-redaction
patterns (passwords, `-p…`, tokens, DSN credentials). The browser polls status +
log by byte offset and resumes polling after a reload.

## Locking / concurrency

Only one active update job is allowed (store-enforced; stale jobs are reaped). The
worker's `BackupService` and `RestoreService` acquire their own operation locks,
so backup/recovery cannot interleave with an update's snapshot/rollback.

## Security

Admin + Pro + TYPO3 request token on every endpoint; POST for state changes;
package-name validation; argv-only execution (no `exec`/`shell_exec`/backticks/
concatenation) except the one detached-worker launcher, which interpolates only a
strict `YYYYMMDD-HHMMSS-xxxxxxxx` job id; timeout + idle-timeout on every process;
secret redaction in logs; no credentials or stack traces in JSON; mandatory safety
backup; maintenance preservation; no fake success.

## Deployment / operations

```bash
composer dumpautoload
vendor/bin/typo3 cache:flush           # rebuild DI container after Services.yaml change
```

Requirements on the server:
- Composer-mode TYPO3 project with a writable `vendor/` and `var/`.
- A real `composer.phar` (project root or a configured path) — a shell-wrapper
  `composer` is intentionally not used.
- A PHP **CLI** binary (configured in Guardian settings or auto-detected).
- `proc_open` enabled (Symfony Process). Run the worker/site as the project owner,
  **not** root.

## Manual runtime test

1. Guardian → Update → **Run analysis** (no blocking errors).
2. **Check online** → statuses populate; **Reload packages** works.
3. **Dry run** → job runs, log streams planned changes, nothing changes on disk.
4. **Start update** (tick confirm) → safety backup → maintenance → composer →
   schema → cache → verify → back online; progress + steps + log update live.
5. Reload the page mid-run → polling resumes.
6. **Recent update jobs** lists the finished job.
7. Force a failure (e.g. an impossible selective set) → precise error; if the tree
   changed, rollback runs and maintenance is handled; **Roll back** offered for a
   failed job with a snapshot.

## Limitations

- Rollback without `vendor/` in the snapshot restores composer files + DB +
  config but may require a controlled `composer install` to rebuild `vendor/`.
- The database schema step applies only additive/safe changes; destructive schema
  changes must be reviewed and applied manually.
