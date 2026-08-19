# Recovery Safety (post-incident hardening)

## What broke a live site

The previous recovery restored directory components — including `vendor/` — by
**wiping the live directory in place and extracting the archive over it**
(`RestoreService::restoreEntries()` → `wipeDirectory()` then `extractEntries()`
into the live project path). For `vendor/` this is catastrophic:

- the live vendor is deleted **before** any replacement is ready (non-atomic);
- if the archived vendor is incomplete, from another environment, macOS-generated,
  contains absolute/broken symlinks, or the archive was truncated, the site is
  left with a half-populated vendor and no way back;
- the running PHP process can no longer autoload the very classes it needs to
  finish the restore.

Root cause: **in-place, non-staged, non-atomic vendor overwrite with no rebuild,
no verification and no retained previous vendor.**

## The hardened model

Direct vendor overwrite is removed. `vendor/` is never wiped in place — a hard
guard in `RestoreService::wipeDirectory()` refuses any path named `vendor`, and
vendor is no longer part of the in-place restore order at all.

### Vendor strategies (`VendorRestoreStrategy`)

- **Rebuild (default)** — restore `composer.json`/`composer.lock`, then
  `composer install` in an isolated staging directory and switch it in atomically.
- **Skip** — do not touch vendor.
- **Archived (advanced, high risk)** — only when strict checks pass; still staged,
  validated and switched atomically. Requires typing `RESTORE VENDOR`.

The legacy `vendor: true` component flag is **rejected server-side** in both the
backend and the standalone panel; vendor is controlled only by the strategy.

### Staged rebuild + atomic switch (`VendorRecoveryService`, `AtomicDirectorySwitch`)

1. Validate restored `composer.json` + `composer.lock` (valid JSON).
2. Validate PHP CLI + composer binary.
3. Build an isolated dir co-located with the live vendor (guaranteed same
   filesystem), symlinking project files so path repositories resolve, and copy
   the restored composer files in.
4. `composer install --no-interaction --no-progress --no-scripts
   --optimize-autoloader --working-dir=<staging>` — never against live vendor.
5. Validate the staged vendor: `autoload.php`, `composer/autoload_real.php`,
   `composer/installed.php`, `composer/installed.json` exist; `typo3/cms-core`
   present; installed set matches `composer.lock`; and every symlink stays inside
   the project root.

   **Symlink rule.** The trust boundary is the **project root**, not the vendor
   subtree, and containment is judged **lexically** (via `PathNormalizer`) at each
   link's final live location — never with `realpath()`, which would tunnel
   through the staging build-directory symlinks and mis-judge containment. This
   accepts the two symlink kinds Composer legitimately creates — bin proxies
   (`vendor/bin/typo3 -> ../typo3/cms-cli/typo3`, inside vendor) and local
   path-repository links (`vendor/acme/ext -> ../../packages/ext`, inside the
   project but outside vendor) — while still rejecting any symlink whose
   normalized target escapes the project root as an arbitrary external symlink.
   Rejections are reported to the administrator with the relative link path, the
   raw target, the normalized target and the reason.
6. Atomic switch: `rename(vendor → .guardian-old-vendor-<job>)` then
   `rename(<staging>/vendor → vendor)` — two renames on one filesystem. The site
   never lacks a `vendor/`. Verify `vendor/autoload.php`; on failure the previous
   vendor is restored immediately.
7. The previous vendor is **retained** until the whole recovery succeeds; only
   then is it discarded. If atomic rename is impossible (different filesystems),
   recovery is **blocked** — there is no recursive-overwrite fallback.

### Transaction journal (`RecoveryTransactionJournal`)

Before any destructive step, `var/guardian/recovery/<job-id>/transaction.json` is
written and updated atomically (temp + rename) after each step, recording the
step, moved/created paths, old/new vendor paths, DB state, previous maintenance
state, safety-snapshot id and rollback state. On the next panel/backend load an
incomplete transaction is detected, blocks any new recovery, and offers a safe
rollback (`rollbackInterrupted()`).

### Mandatory dry run (`RecoveryDryRun`)

A real recovery is refused until a successful dry run exists for the **exact**
backup + components + vendor strategy (a fingerprint of that selection). The dry run
validates the archive, checks composer files, atomic-switch capability and disk
space (archive + snapshot + staged vendor + retained old vendor ≈ 4×) and makes
no changes, enables no maintenance and restores no database. Changing any
selection invalidates the fingerprint.

### Mandatory safety snapshot

A pre-recovery snapshot (composer + DB + selected files, **excluding vendor** —
vendor rollback is the atomic old-vendor rename, not an archive) is always taken;
`restore()` refuses to run without it.

### Post-recovery verification

When vendor was touched, success is only reported after:
- `vendor/autoload.php` loads in a **separate** PHP CLI process, and
- `vendor/bin/typo3 --version` bootstraps (TYPO3 13.4 + 14 compatible).

Failure triggers automatic rollback.

### Rollback

On any failure after changes begin: the vendor switch is reverted (old vendor
restored atomically; broken tree kept as `.guardian-failed-vendor-<job>` for
diagnosis), non-vendor components are restored from the safety snapshot,
maintenance is kept ON until rollback completes, and the outcome is reported as
one of *rolled back* / *rollback incomplete* — never generic success.

### Same engine everywhere

The backend Recovery tab and the standalone `_guardian-recovery.php` call the
**same** `RestoreService` / `VendorRecoveryService` / `RecoveryDryRun` /
`RecoveryTransactionJournal` via `StandaloneRecoveryKernel`. There is no safer
backend path and unsafe panel path.

## Disk-space requirements

Plan for roughly: backup archive + safety snapshot + staged vendor + retained
old vendor. The dry run blocks recovery when free space is below ~4× the archive.

## Emergency manual rollback

If a recovery is interrupted, open the panel/backend: the interrupted transaction
is shown with a **Roll back** action. Manually, the previous vendor is at
`<project>/.guardian-old-vendor-<job-id>` — restore it with
`mv vendor .broken-vendor && mv .guardian-old-vendor-<job-id> vendor` as the
project user, then flush caches.
