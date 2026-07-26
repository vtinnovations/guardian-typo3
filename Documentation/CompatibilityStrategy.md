# Compatibility Strategy — TYPO3 13.4 / 14

How Guardian supports **TYPO3 13.4.9 through 14.x** from a single package, and the
rules that keep it that way.

## Supported versions

| Axis | Support |
|---|---|
| TYPO3 | **13.4.9** (minimum) through **14.x** |
| PHP | **8.2** minimum, through 8.5 (`~8.2.0 \|\| ~8.3.0 \|\| ~8.4.0 \|\| ~8.5.0`) |
| Installation mode | Composer (primary and only officially supported mode) |

The effective PHP floor on any given site is whatever the installed TYPO3 core
requires (TYPO3 13.4 runs on PHP 8.2+; TYPO3 14 raises the floor). Guardian itself
imposes only the 8.2 minimum so it never blocks a valid TYPO3 13.4 install.

## Composer constraints

Applied consistently to every directly required `typo3/cms-*` package:

```json
"require": {
    "php": "~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0",
    "ext-json": "*",
    "typo3/cms-core": "^13.4.9 || ^14.0",
    "typo3/cms-backend": "^13.4.9 || ^14.0",
    "psr/log": "^3.0",
    "psr/http-message": "^1.1 || ^2.0"
}
```

- **No `typo3/cms` metapackage** — only the two subpackages actually used.
- `psr/http-message: ^1.1 || ^2.0` matches the range TYPO3 13.4/14 themselves
  allow; Composer resolves it to whatever the installed core pins.
- Dev tooling spans both eras: `phpunit/phpunit: ^10.5 || ^11.0`,
  `typo3/testing-framework: ^8.2.3 || ^9.0` — Composer selects the combination
  compatible with the installed TYPO3.

## Why no `ext_emconf.php`

The only supported installation mode is Composer. In Composer mode TYPO3 derives
all extension metadata (extension key, title, version constraints, dependencies)
from `composer.json` (`type: typo3-cms-extension`, `extra.typo3/cms.extension-key`,
and the `typo3/cms-*` version constraints). An `ext_emconf.php` would be needed
only for **Classic (non-Composer) installs**, which Guardian does not target, and
would then have to duplicate the constraints already in `composer.json` — a
guaranteed source of drift and conflicting declarations.

Therefore `ext_emconf.php` is intentionally **omitted**. Should Classic-mode
support ever be added, the file must declare exactly: TYPO3 `13.4.9`–below-`15`
(`'typo3' => '13.4.9-14.4.99'`), PHP min `8.2.0`, extension key `guardian_typo3`,
state `beta`/`stable` as appropriate — matching `composer.json` with no conflict.

## Shared-subset policy

The default is **one shared implementation**. TYPO3-specific code is confined to
`Classes/Typo3/` adapters, the single backend controller, and
`Configuration/`+`Resources/`. Within that surface, only APIs present and
identical in both 13.4.9 and 14 are used:

- Array-based backend-module registration (`routes/_default/target`).
- `ModuleTemplateFactory` / `ModuleTemplate` (incl. `renderResponse()`, v12+).
- `UriBuilder::buildUriFromRoute()`.
- `Environment` path/mode accessors.
- Backend user via `BackendUserAuthentication` (adapter-isolated).
- `Configuration/Icons.php` + `SvgIconProvider`.
- Symfony DI (`Configuration/Services.yaml`) with no v14-only keys.
- Core Fluid ViewHelpers only.
- Plain browser JavaScript (no `@typo3/backend/*` import).

**No runtime version comparison (`version_compare`, `VersionNumberUtility`) is
used anywhere** — where a shared API exists, it is used directly.

## Adapters introduced for version differences

**None.** No TYPO3 13.4 ↔ 14 API incompatibility was found (see
`CompatibilityAudit.md`). The `Classes/Typo3/` adapters exist to isolate the CMS
from the pure domain — a design boundary, not a version shim.

If a genuine incompatibility appears in a later phase, the rule is:

1. Define an application-facing interface (a port) in `Application/Contract/`.
2. Put each version's behaviour in a separate infrastructure/TYPO3 adapter.
3. Select the adapter via DI / a capability check in the adapter — **not** via a
   `version_compare` sprinkled through controllers or domain code.
4. Keep controllers and domain services version-neutral.
5. Document the adapter here and in `CompatibilityAudit.md`.

## APIs deliberately avoided

- The `typo3/cms` metapackage (pulls in unneeded packages).
- Any backend-module wiring that only exists from v14.
- Deprecated RequireJS/`define()` global JS modules.
- Runtime major-version branching.

## CI matrix (to be executed in isolated installs)

| TYPO3 | PHP | Purpose |
|---|---|---|
| 13.4.9 (lowest) | 8.2 | absolute minimum baseline |
| latest 13.4.x | 8.3 (or 8.4) | current 13.4 LTS line |
| lowest supported 14.0 | 8.2 → resolves to core's floor | 14 entry point |
| latest 14.x | latest supported PHP | current 14 line |

`--prefer-lowest` runs on the 13.4.9/PHP-8.2 cell verify the minimum constraints
actually resolve. See `Testing.md` for the exact commands.

## Release & deprecation policy

- One package, one branch, supports 13.4.9→14.x for the lifetime of TYPO3 13.4 LTS.
- Bugfixes target the shared codebase; no per-version forks.
- Deprecations are avoided by staying on the shared subset; if a later TYPO3 15
  removes something, support is added via an adapter (per the rule above), never a
  breaking change to the shared code.

## Dropping TYPO3 13 (future)

When TYPO3 13.4 LTS reaches end of life:

1. Bump `typo3/cms-*` to `^14.4 || ^15.0` (or as appropriate) in a new **major**
   Guardian release; keep the 13.4-compatible line on a maintenance branch.
2. Raise the PHP floor to TYPO3 14's minimum.
3. Remove any 13.4-only adapters (currently none) and simplify.
4. Update `CompatibilityAudit.md`, this file, `Testing.md`, and `README.md`.

## Known runtime checks still required

Static analysis cannot substitute for installing on real cores. Before release,
run the CI matrix above and manually verify the backend module on **both** a
13.4.9 and a 14.x instance (module appears under System for admins; all seven
sections render; DI container compiles; icon/labels/XLF resolve).
