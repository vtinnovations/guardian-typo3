# Standalone Recovery Panel

The standalone recovery panel is an **opt-in, Pro-only** emergency entrypoint that
lets an authorised admin recover a Guardian backup **even when the TYPO3 backend
is unavailable**. It is disabled by default and must be explicitly enabled and
deployed from the Guardian backend module (Recovery tab).

## Design principle: one restore engine

The public entrypoint (`public/<filename>`, default `_guardian-recovery.php`) is a
**thin bootstrap + controller only**. It contains no restore logic of its own.
All real work is performed by the **same** Guardian recovery application services
the backend uses:

| Concern | Shared service |
| --- | --- |
| Backup index + full verification | `Application\Recovery\BackupCatalog` |
| Read-only preflight | `Application\Recovery\RecoveryPreflight` |
| Staged restore, snapshot, rollback, maintenance | `Application\Recovery\RestoreService` |
| Safe archive extraction | `Infrastructure\Recovery\ZipBackupArchiveExtractor` |
| DB dump / import | `Infrastructure\Database\Typo3Database{Dumper,Importer}` |
| Maintenance marker | `Infrastructure\Maintenance\FileMaintenanceMode` |

`Recovery\Standalone\StandaloneRecoveryKernel` hand-wires those classes from the
panel's own disk location. Because the DB dumper/importer read their connection
config from `$GLOBALS['TYPO3_CONF_VARS']`, the kernel loads
`config/system/settings.php` into that global before any recovery — no TYPO3 boot
is required.

## Path discovery

The panel lives in the public web root. The kernel derives:

- `publicPath = dirname(__FILE__)`
- `projectPath = dirname(publicPath)`
- `varPath = projectPath . '/var'` → Guardian state under `var/guardian/`.

## Security model

- **Disabled by default.** An un-enabled panel responds like a missing file (404).
- **Token.** Generated from a cryptographically secure random source and stored
  only in non-recoverable form, in a restricted file outside the web root. The
  plaintext is shown **once**, at generation or rotation, and is never persisted
  or logged; afterwards the interface shows only a masked preview. Comparison is
  timing-safe. A token may also be supplied through the `GUARDIAN_RECOVERY_TOKEN`
  server environment variable instead of the stored one.
- **Sessions.** PHP session cookie is `HttpOnly`, `SameSite=Strict`, `Secure` on
  HTTPS. The session id is regenerated after login (fixation defence). Idle
  timeout 15 min, absolute lifetime 1 h. Rotating the token invalidates existing
  sessions.
- **CSRF.** A per-session token guards every state-changing request.
- **Rate limiting.** Failed attempts are counted per client without storing the
  address in recoverable form; 5 failures / 15 min → 15 min lockout; entries
  auto-expire. Failures return a single generic message that never reveals how
  close an attempt was.
- **No secret in the deployed file.** The token lives outside the web root; the
  deployed file holds no secret of its own.
- **Fail-safe errors.** `display_errors` off; a global exception handler renders a
  generic message — never a stack trace, class name or absolute path.
- **Response headers.** `X-Content-Type-Options`, `X-Frame-Options: DENY`,
  `Referrer-Policy: no-referrer`, a strict `Content-Security-Policy`, and
  `noindex`.

## Deployment safety

Deployment is managed by Guardian and:

- Writes an **ownership signature** marker
  (`GUARDIAN-RECOVERY-PANEL:MANAGED-ENTRYPOINT`) — Guardian only ever removes
  files carrying it. **An operator's own same-named file is never deleted or
  overwritten.**
- Deploys **atomically** (temp file + `rename`).
- On a filename change, deploys the **new** entrypoint first and only then removes
  the previous managed one.
- Disabling removes the managed entrypoint, genuinely eliminating the exposure.

## State layout (outside the web root)

```
var/guardian/recovery-panel/
  config.json       # panel enabled flag and filename
  token.json        # authentication material, non-recoverable form only
  rate-limit.json   # failed-attempt counters
  audit.log         # panel/token/login/recovery lifecycle events (no secrets)
  panel.log         # standalone runtime log
```

## Backend endpoints (admin + Pro; POST for writes; CSRF via route token)

Panel status, filename, deployment, disabling, token generation and rotation,
panel test, and the recovery list/preflight/run/history operations are each
served by their own backend endpoint. The raw token is returned only in the
generate/rotate response.

## Enabling (operator procedure)

1. Guardian → **Recovery** tab.
2. **Generate token** → copy the shown token to a password manager (shown once).
3. Optionally change the panel filename → **Save**.
4. **Enable & deploy panel**.
5. Verify the panel URL loads and the token authenticates.
6. To remove the exposure later: **Disable & remove panel**.
