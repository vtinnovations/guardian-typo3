# Kompatibilitätsaudit — TYPO3 13.4.9 ↔ TYPO3 14

Statisches Audit jeder TYPO3-berührenden Nutzung in der Extension, geprüft auf
Verfügbarkeit in **TYPO3 13.4.9** (Mindestversion) und **TYPO3 14.x**. Das
Ports-and-Adapters-Design der Extension begrenzt jede TYPO3-API-Nutzung auf die
`Typo3/`-Adapter, den einzelnen Backend-Controller sowie die Dateien unter
`Configuration/` + `Resources/` — die Audit-Oberfläche ist daher klein.

> Methode: Jeder Import und Aufruf wurde gegen die öffentliche TYPO3-13.4- und
> -14-API geprüft (die array-basierte Backend-Modul-API und
> `ModuleTemplate::renderResponse()` stammen beide aus der v12.0-„neuen
> Backend-Modul-API“ und sind über 13.4 und 14 hinweg unverändert; PSR-7, DI,
> Icons, `Environment` und die hier verwendeten Core-Fluid-ViewHelper sind
> langfristig stabil. Keine verwendete API ist neuer als TYPO3 v12.0, sodass
> alle bereits in 13.4.9 vorhanden sind. **In dieser Umgebung war keine
> Laufzeit-/PHP-Verifikation möglich** — siehe „Noch erforderliche
> Laufzeitprüfungen“.

## Ergebniszusammenfassung

- **Nur-TYPO3-14-APIs gefunden: keine.**
- **Für jede Nutzung ist eine gemeinsame Implementierung möglich.**
- **Erforderliche versionsspezifische Adapter: keine** (die vorhandenen
  `Typo3/`-Adapter isolieren das CMS von der Domain, nicht eine
  TYPO3-Version von einer anderen).
- Tatsächlich für die Unterstützung von 13.4.9 erforderliche Änderungen: **nur
  Composer-Constraints**, plus eine defensive Änderung an der `position` des
  Backend-Moduls (siehe Tabelle).

## Audit je Verwendung

| Datei / Klasse | Verwendete API | 13.4.9 | 14.x | Gemeinsam? | Erforderliche Änderung |
|---|---|:--:|:--:|:--:|---|
| `Configuration/Backend/Modules.php` | array-basierte Modulregistrierung (`parent`, `access`, `routes/_default/target`, `iconIdentifier`, `path`, `labels`) | ✅ (v12+) | ✅ | ja | keine (API identisch) |
| `Configuration/Backend/Modules.php` | Gruppe `parent => 'system'` | ✅ | ✅ | ja | keine — Gruppe `system` existiert in beiden |
| `Configuration/Backend/Modules.php` | `position => ['after' => 'system_BackendUserManagement']` | ⚠️ | ⚠️ | n/a | **geändert** zu `['bottom']` — ein Geschwister-Modul-Identifier ist zwischen 13.4/14 nicht garantiert identisch; stattdessen versionsneutraler Hinweis verwendet |
| `Configuration/Backend/Modules.php` | `labels` → XLF mit `mlang_tabs_tab`/`mlang_labels_tablabel`/`mlang_labels_tabdescr` | ✅ | ✅ | ja | keine — das Legacy-Label-Trio wird in beiden weiterhin aufgelöst |
| `Configuration/Icons.php` | Rückgabe-Array + `SvgIconProvider` | ✅ (v11+) | ✅ | ja | keine |
| `Configuration/Services.yaml` | `_defaults` Autowire/Autoconfigure, `resource`/`exclude`, Interface-`alias`, `public` | ✅ | ✅ | ja | keine — keine nur-v14-DI-Schlüssel |
| `Controller/Backend/GuardianModuleController` | `ModuleTemplateFactory::create($request)` | ✅ | ✅ | ja | keine |
| `Controller/Backend/GuardianModuleController` | `ModuleTemplate::setTitle(string, string)` | ✅ | ✅ | ja | keine |
| `Controller/Backend/GuardianModuleController` | `ModuleTemplate::assign()` / `assignMultiple()` | ✅ | ✅ | ja | keine |
| `Controller/Backend/GuardianModuleController` | `ModuleTemplate::renderResponse(string): ResponseInterface` | ✅ (v12.0+) | ✅ | ja | keine — **keine** nur-v14-Methode |
| `Controller/Backend/GuardianModuleController` | `UriBuilder::buildUriFromRoute(string, array)` (per Konstruktor injiziert) | ✅ | ✅ | ja | keine |
| `Controller/Backend/GuardianModuleController` | PSR-7 `ServerRequestInterface::getQueryParams()`, `ResponseInterface`-Rückgabe | ✅ | ✅ | ja | keine — PSR-7 identisch |
| `Typo3/Environment/Typo3ProjectEnvironment` | `Environment::getProjectPath/getVarPath/getPublicPath/isComposerMode()` | ✅ | ✅ | ja | keine |
| `Typo3/Authorization/BackendUserAuthorization` | `$GLOBALS['BE_USER']`, `BackendUserAuthentication::isAdmin()`, `->user['username']` | ✅ | ✅ | ja | keine — im Adapter isoliert (nicht in der Domain) |
| `Typo3/Logging/Typo3SystemLogger` | Autokonfiguration von `Psr\Log\LoggerAwareInterface`/`LoggerAwareTrait` | ✅ | ✅ | ja | keine |
| `Typo3/Scheduler/Typo3SchedulerIntegration` | `ExtensionManagementUtility::isLoaded()` | ✅ | ✅ | ja | keine |
| `Resources/Private/**/*.html` | Fluid-ViewHelper `f:layout`, `f:section`, `f:render`, `f:for`, `f:if/then/else`, `f:translate`, `f:format.date`, `f:comment`, `f:asset.css`, `f:asset.script` | ✅ | ✅ | ja | keine — alle Core-ViewHelper, unverändert |
| `Resources/Private/**/*.html` | globaler Namespace `data-namespace-typo3-fluid="true"` | ✅ | ✅ | ja | keine |
| `Resources/Public/JavaScript/guardian.js` | reines ES/DOM (kein TYPO3-Backend-Import) | ✅ | ✅ | ja | keine — kein `@typo3/*`- oder RequireJS-Import, der brechen könnte |
| `Resources/Private/Language/*.xlf` | XLIFF 1.0 mit `mlang_*` + `section.*`-Schlüsseln | ✅ | ✅ | ja | keine |

## Explizit geprüft und als NICHT vorhanden befunden (gut)

- **Nur-TYPO3-14-Klassen / -Methoden / -Konstruktorsignaturen**: keine
  verwendet.
- **Entfernte/umbenannte TYPO3-13-APIs**: keine verwendet (nichts hängt von
  einer API ab, die 13.4 fehlt).
- **Geänderte PSR-7-Request-Verarbeitung**: nicht betroffen — nur
  `getQueryParams()` genutzt.
- **Geänderte Backend-Modul-API**: Die array-/`routes`-API ist in beiden
  dieselbe API aus der v12-Ära; es wird keine nur `BackendViewFactory`- oder
  nur-v14-Modulverdrahtung verwendet.
- **Geänderte Fluid-API**: kein Nur-v14-ViewHelper oder -Argument verwendet.
- **Geänderte JS-Importpfade**: keine `@typo3/backend/*`-Importe vorhanden, die
  sich unterscheiden könnten.
- **Geänderte Icon-Registrierung**: `Configuration/Icons.php` + `SvgIconProvider`
  identisch.
- **Geänderte Lokalisierungssyntax**: XLIFF 1.0 unverändert.
- **Geänderte Service-Konfiguration / DI-Verhalten / PHP-Attribute**: nichts
  versionsspezifisch (es werden noch keine Attribute im Stil von
  `#[AsController]`/`#[AsEventListener]` verwendet; die Verdrahtung erfolgt in
  reinem YAML).
- **Geänderte Event-Klassen**: In Phase 1 sind keine Event-Listener
  registriert.
- **Annahmen über die v14-Verzeichnisstruktur / Paketnamen**: keine — Pfade
  stammen aus `Environment`, und nur `typo3/cms-core` + `typo3/cms-backend`
  werden benötigt.

## Noch erforderliche Laufzeitprüfungen (hier nicht ausführbar)

1. Installation auf einer echten **TYPO3-13.4.9**-Instanz und Bestätigung,
   dass das Modul für Administratoren unter *System* erscheint und jeder
   Abschnitt rendert (Template-Auflösung).
2. Wiederholung auf **TYPO3 14.x**.
3. Bestätigung, dass der DI-Container in beiden kompiliert (Service-Aliase +
   Konstruktor-Graph).
4. Bestätigung, dass Icon, Labels und XLF-Übersetzungen in beiden aufgelöst
   werden.
