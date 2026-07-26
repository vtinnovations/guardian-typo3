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
- **Token.** Generated with `random_bytes(36)` (URL-safe base64, ≥43 chars),
  stored **only as a SHA-256 hash** under `var/guardian/recovery-panel/token.json`
  (0600). The plaintext is shown **once**, at generation/rotation, never persisted
  or logged. Later the UI shows only a masked preview. Verification is
  constant-time (`hash_equals`). `GUARDIAN_RECOVERY_TOKEN` env var overrides.
- **Sessions.** PHP session cookie is `HttpOnly`, `SameSite=Strict`, `Secure` on
  HTTPS. The session id is regenerated after login (fixation defence). Idle
  timeout 15 min, absolute lifetime 1 h. Rotating the token invalidates existing
  sessions (session stores a token fingerprint).
- **CSRF.** A per-session token guards every state-changing request.
- **Rate limiting.** `PanelRateLimiter` keys attempts by a **hashed** IP under
  `var/guardian/recovery-panel/rate-limit.json`; 5 failures / 15 min → 15 min
  lockout; entries auto-expire. Failures return a single generic message and
  never reveal whether the token prefix was correct.
- **No secret in the deployed file.** The token lives outside the webroot; the
  file only compares against the hash.
- **Fail-safe errors.** `display_errors` off; a global exception handler renders a
  generic message — never a stack trace, class name or absolute path.
- **Response headers.** `X-Content-Type-Options`, `X-Frame-Options: DENY`,
  `Referrer-Policy: no-referrer`, a strict `Content-Security-Policy`, and
  `noindex`.

## Deployment safety

`Infrastructure\Recovery\RecoveryPanelDeployer`:

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
  config.json       # { enabled: false (default), filename }
  token.json        # { algo, hash, preview, created_at } — hash only
  rate-limit.json   # hashed-IP attempt counters
  audit.log         # panel/token/login/recovery lifecycle events (no secrets)
  panel.log         # standalone runtime log
```

## Backend endpoints (admin + Pro; POST for writes; CSRF via route token)

`panelStatus`, `panelSaveFilename`, `panelDeploy`, `panelDisable`,
`panelTokenGenerate`, `panelRotate`, `panelTest`, `recoveryList`,
`recoveryPreflight`, `recoveryRun`, `recoveryHistory`
(see `Configuration/Backend/AjaxRoutes.php`). The raw token is returned only in the
generate/rotate response.

## Enabling (operator procedure)

1. Guardian → **Recovery** tab.
2. **Generate token** → copy the shown token to a password manager (shown once).
3. Optionally change the panel filename → **Save**.
4. **Enable & deploy panel**.
5. Verify the panel URL loads and the token authenticates.
6. To remove the exposure later: **Disable & remove panel**.
