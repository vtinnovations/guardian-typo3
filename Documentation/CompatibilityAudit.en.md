# Compatibility Audit — TYPO3 13.4.9 ↔ TYPO3 14

Static audit of every TYPO3-touching usage in the extension, checked for
availability in **TYPO3 13.4.9** (minimum supported) and **TYPO3 14.x**. The
extension's ports-and-adapters design confines all TYPO3 API usage to the
`Typo3/` adapters, the single backend controller, and the `Configuration/` +
`Resources/` files — so the audit surface is small.

> Method: reviewed each import and call against the TYPO3 13.4 and 14 public API
> (the array-based backend-module API and `ModuleTemplate::renderResponse()`
> both date from the v12.0 "new backend module API" and are unchanged through
> 13.4 and 14; PSR-7, DI, Icons, `Environment`, and the core Fluid ViewHelpers
> used here are long-stable). No API used is newer than TYPO3 v12.0, so all are
> present in 13.4.9. **No runtime/PHP verification was possible in this
> environment** — see "Runtime checks still required".

## Result summary

- **TYPO3 14-only APIs found: none.**
- **One shared implementation is possible for every usage.**
- **Version-specific adapters required: none** (the existing `Typo3/` adapters
  isolate the CMS from the domain, not one TYPO3 version from another).
- Changes actually required to add 13.4.9 support: **Composer constraints only**,
  plus a defensive change to the backend-module `position` (see the table).

## Per-usage audit

| File / class | API used | 13.4.9 | 14.x | Shared? | Required modification |
|---|---|:--:|:--:|:--:|---|
| `Configuration/Backend/Modules.php` | array module registration (`parent`, `access`, `routes/_default/target`, `iconIdentifier`, `path`, `labels`) | ✅ (v12+) | ✅ | yes | none (API identical) |
| `Configuration/Backend/Modules.php` | `parent => 'system'` group | ✅ | ✅ | yes | none — `system` group exists in both |
| `Configuration/Backend/Modules.php` | `position => ['after' => 'system_BackendUserManagement']` | ⚠️ | ⚠️ | n/a | **changed** to `['bottom']` — a sibling module identifier is not guaranteed identical across 13.4/14; version-neutral hint used instead |
| `Configuration/Backend/Modules.php` | `labels` → XLF with `mlang_tabs_tab`/`mlang_labels_tablabel`/`mlang_labels_tabdescr` | ✅ | ✅ | yes | none — legacy label trio still resolved in both |
| `Configuration/Icons.php` | return array + `SvgIconProvider` | ✅ (v11+) | ✅ | yes | none |
| `Configuration/Services.yaml` | `_defaults` autowire/autoconfigure, `resource`/`exclude`, interface `alias`, `public` | ✅ | ✅ | yes | none — no v14-only DI keys |
| `Controller/Backend/GuardianModuleController` | `ModuleTemplateFactory::create($request)` | ✅ | ✅ | yes | none |
| `Controller/Backend/GuardianModuleController` | `ModuleTemplate::setTitle(string, string)` | ✅ | ✅ | yes | none |
| `Controller/Backend/GuardianModuleController` | `ModuleTemplate::assign()` / `assignMultiple()` | ✅ | ✅ | yes | none |
| `Controller/Backend/GuardianModuleController` | `ModuleTemplate::renderResponse(string): ResponseInterface` | ✅ (v12.0+) | ✅ | yes | none — **not** a v14-only method |
| `Controller/Backend/GuardianModuleController` | `UriBuilder::buildUriFromRoute(string, array)` (constructor-injected) | ✅ | ✅ | yes | none |
| `Controller/Backend/GuardianModuleController` | PSR-7 `ServerRequestInterface::getQueryParams()`, `ResponseInterface` return | ✅ | ✅ | yes | none — PSR-7 identical |
| `Typo3/Environment/Typo3ProjectEnvironment` | `Environment::getProjectPath/getVarPath/getPublicPath/isComposerMode()` | ✅ | ✅ | yes | none |
| `Typo3/Authorization/BackendUserAuthorization` | `$GLOBALS['BE_USER']`, `BackendUserAuthentication::isAdmin()`, `->user['username']` | ✅ | ✅ | yes | none — isolated in adapter (not in domain) |
| `Typo3/Logging/Typo3SystemLogger` | `Psr\Log\LoggerAwareInterface`/`LoggerAwareTrait` autoconfiguration | ✅ | ✅ | yes | none |
| `Typo3/Scheduler/Typo3SchedulerIntegration` | `ExtensionManagementUtility::isLoaded()` | ✅ | ✅ | yes | none |
| `Resources/Private/**/*.html` | Fluid VHs `f:layout`, `f:section`, `f:render`, `f:for`, `f:if/then/else`, `f:translate`, `f:format.date`, `f:comment`, `f:asset.css`, `f:asset.script` | ✅ | ✅ | yes | none — all core ViewHelpers, unchanged |
| `Resources/Private/**/*.html` | `data-namespace-typo3-fluid="true"` global namespace | ✅ | ✅ | yes | none |
| `Resources/Public/JavaScript/guardian.js` | plain ES/DOM (no TYPO3 backend import) | ✅ | ✅ | yes | none — no `@typo3/*` or RequireJS import to break |
| `Resources/Private/Language/*.xlf` | XLIFF 1.0 with `mlang_*` + `section.*` keys | ✅ | ✅ | yes | none |

## Items explicitly checked and found NOT present (good)

- **TYPO3 14-only classes / methods / constructor signatures**: none used.
- **Removed/renamed TYPO3 13 APIs**: none used (nothing depends on an API that
  13.4 lacks).
- **Changed PSR-7 request handling**: not affected — only `getQueryParams()`.
- **Changed backend module API**: the array/`routes` API is the same v12-era API
  in both; no `BackendViewFactory`-only or v14-only module wiring is used.
- **Changed Fluid API**: no v14-only ViewHelper or argument used.
- **Changed JS import paths**: no `@typo3/backend/*` imports exist to differ.
- **Changed icon registration**: `Configuration/Icons.php` + `SvgIconProvider`
  identical.
- **Changed localization syntax**: XLIFF 1.0 unchanged.
- **Changed service configuration / DI behaviour / PHP attributes**: none
  version-specific (no `#[AsController]`/`#[AsEventListener]`-style attributes are
  used yet; wiring is plain YAML).
- **Changed event classes**: no event listeners registered in Phase 1.
- **Assumptions about v14 directory structure / package names**: none — paths come
  from `Environment`, and only `typo3/cms-core` + `typo3/cms-backend` are required.

## Runtime checks still required (could not run here)

1. Install on a real **TYPO3 13.4.9** instance and confirm the module appears
   under *System* for admins and every section renders (template resolution).
2. Repeat on **TYPO3 14.x**.
3. Confirm DI container compiles on both (service aliases + constructor graph).
4. Confirm the icon, labels and XLF translations resolve on both.
