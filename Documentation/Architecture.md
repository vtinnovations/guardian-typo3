# Architektur — Guardian für TYPO3

Guardian ist als geschichtete Ports-and-Adapters-Architektur aufgebaut. Die
zentrale Entwurfsregel, gewonnen aus der Auditierung des Contao-Originals,
lautet:

> **Die Kernlogik für Backup, Jobs, Zeitpläne und Lizenzierung darf niemals von
> TYPO3-Globals, dem Dateisystem, der Uhr oder einem externen Prozess abhängen.**
> Alles Umgebungsspezifische wird über eine Schnittstelle (einen *Port*)
> erreicht, die von einem schlanken *Adapter* implementiert wird.

Das hält die wertvollen, hart erarbeiteten Geschäftsregeln (Zeitplan-Mathematik,
Berechtigungsfenster, Archiv-Sicherheit, Job-Lebenszyklus) rein und
unit-testbar und begrenzt die riskanten, framework- und
betriebssystemspezifischen Teile auf kleine, einzeln überprüfbare Nahtstellen.

## Schichten

```
Classes/
├── Domain/            Reine Geschäftsregeln & Value Objects. Keine I/O, kein
│                      TYPO3, keine Uhr, keine Globals. Vollständig unit-testbar.
├── Application/       Use-Case-Services + Ports (Contract/). Orchestrieren die
│                      Domain; hängen nur von Schnittstellen ab, nie von Adaptern.
├── Infrastructure/    Framework-neutrale Adapter: JSON-Repositories, flock,
│                      Uhr, Pfade, der (in Phase 1 verweigernde) Command-Executor.
├── Typo3/             TYPO3-spezifische Adapter. Der EINZIGE Code, der
│                      Environment, BE_USER, TYPO3-Logging, Scheduler, Mailer berührt.
└── Controller/Backend/ Backend-Rand: wandelt einen Request in Service-Aufrufe
                        und eine Fluid-Antwort um. Keine Geschäftslogik.
```

Die Abhängigkeitsrichtung verläuft strikt nach innen: `Controller → Application
→ Domain`, wobei `Infrastructure` und `Typo3` Application-Ports implementieren
und ausschließlich in `Configuration/Services.yaml` verdrahtet werden. Die
Domain hängt von nichts ab.

## Die Ports (CMS-/OS-Nahtstellen)

Definiert in `Classes/Application/Contract/` (plus `Domain/Clock/ClockInterface`).
Die Namen verfeinern die im Briefing angeforderten; die Zuordnung lautet:

| Angeforderte Schnittstelle | Dieses Projekt | Phase-1-Adapter |
|---|---|---|
| `BackendAuthorizationInterface` | `BackendAuthorizationInterface` | `Typo3\Authorization\BackendUserAuthorization` |
| `CacheManagerInterface` | `CacheManagerInterface` | *(nur Schnittstelle — Update-Phase)* |
| `MaintenanceModeInterface` | `MaintenanceModeInterface` | *(nur Schnittstelle — Restore-Phase)* |
| `DatabaseSchemaUpdaterInterface` | `DatabaseSchemaUpdaterInterface` | *(nur Schnittstelle — Update-Phase)* |
| `SystemLoggerInterface` | `SystemLoggerInterface` | `Typo3\Logging\Typo3SystemLogger` |
| `CommandExecutorInterface` | `CommandExecutorInterface` | `Infrastructure\Process\UnavailableCommandExecutor` (verweigert) |
| `ProjectEnvironmentInterface` | `ProjectEnvironmentInterface` | `Typo3\Environment\Typo3ProjectEnvironment` |
| `MailerInterface` | `MailerInterface` | *(nur Schnittstelle — Benachrichtigungs-Phase)* |
| `SchedulerIntegrationInterface` | `SchedulerIntegrationInterface` | `Typo3\Scheduler\Typo3SchedulerIntegration` |

Unterstützende Ports, hinzugefügt für eine saubere Phase 1:
`WorkingDirectoryProviderInterface`, `LockInterface` / `LockFactoryInterface`,
`RuntimeConfigurationRepositoryInterface`, `ScheduleRepositoryInterface` sowie
die Ports, die den Berechtigungszustand tragen.

Schnittstellen ohne Phase-1-Adapter sind beabsichtigt: Die Nahtstelle wird
bereits jetzt festgelegt, damit die späteren destruktiven Pipelines von der
Abstraktion abhängen, aber es wird noch keine destruktive Implementierung
ausgeliefert. Der Command-Executor liefert dagegen einen Adapter — einen, der
eine `NotImplementedException` wirft, statt jemals stillschweigend
erfolgreich zu sein.

## Inhalt der Domain

Reine Value Objects und Services, alle mit `declare(strict_types=1)` und, wo
sie Zustand tragen, unveränderlich (immutable):

- **Configuration**: `RuntimeConfiguration` (validiert, unveränderlich).
- **Schedule**: `ScheduleFrequency`, `BackupSchedule`, `ScheduleRun` sowie der
  reine `ScheduleEvaluator` (nahezu wortgetreu aus Contao portiert; Zeit wird
  übergeben).
- **Entitlement**: Value Objects, die die Lizenzstufe, die Host-Bindung und die
  Gültigkeitsregeln beschreiben. Die internen Details von Entitlement werden in
  diesem Dokument absichtlich nicht ausgeführt.
- **Job**: die Enums `JobStatus`/`JobType`/`UpdateMode` sowie das unveränderliche
  `Job` mit einer abgesicherten Zustandsmaschine (`JobStatus::canTransitionTo`).
- **Process**: `CommandRequest` (nur argv, shell-unmöglich) und `CommandResult`.
- **Archive**: `ArchiveEntryValidator` (Zip-/Tar-Slip-Schutz).
- **Filesystem**: `PathNormalizer` (lexikalisch, symlink-unabhängige
  Eingrenzung).
- **Clock**: `ClockInterface`.
- **Exception**: `GuardianException`, `NotImplementedException`,
  `InvalidConfigurationException`.

## Inhalt der Application

- `Configuration\RuntimeConfigurationService` — Konfiguration lesen.
- `Environment\EnvironmentInspector` — baut `EnvironmentCapabilities` auf (kein
  exec).
- Entitlement-Services — Aktivierung, Aktualisierung, Entfernung, Auswertung
  sowie das an jeder geschützten Funktionsgrenze geprüfte Gate. Diese sind über
  die obigen Schichten verteilt statt an einer Stelle konzentriert und werden
  hier nicht einzeln aufgeführt.
- `Schedule\ScheduleForecastService` + `ScheduleForecast` — schreibgeschütztes
  „fällig/nächster Termin“.
- `Dashboard\DashboardService` — aggregiertes Lesemodell für das Modul.

## Backend-UI

Ein Backend-Modul (`guardian`, unter *System*, `access: admin`). Der Controller
`Controller\Backend\GuardianModuleController::handleRequest` validiert einen
`action`-Query-Parameter gegen eine feste Positivliste (Allowlist) und rendert
pro Abschnitt genau ein Fluid-Template. Das 176 KB große monolithische
Contao-Twig-Template wird ersetzt durch:

```
Resources/Private/
├── Templates/Guardian/Index.html   das Produktmodul: Shell + Tab-Panels
├── Partials/Guardian/              Dashboard, Update, Backup, Recovery,
│                                   Extensions, Settings, Tabs
Resources/Public/
├── Css/guardian.css                theme-fähig, gescoped
└── JavaScript/guardian.js          das Skript des Produktmoduls
```

Der gemeinsam genutzte V-T.ONE-Lizenzbildschirm hat daneben sein eigenes
Template, Partial und Skript.

Kein Inline-JavaScript. Der Berechtigungszustand wird an beiden Stellen
serverseitig gerendert, sodass kein Bildschirm davon abhängt, dass ein Request
abgeschlossen wird, um den gespeicherten Zustand anzuzeigen.

Lizenzsteuerungen existieren **ausschließlich** auf dem gemeinsamen Bildschirm
unter *System → VTOne Licensing*. Das Produktmodul hat kein Lizenzpanel, keine
Lizenz-Endpunkte in seiner Endpunkt-Zuordnung und keinen Lizenzcode in seinem
Skript; sein Settings-Tab verlinkt auf den gemeinsamen Bildschirm.

## Was konzeptionell ähnlich bleibt vs. was neu entworfen wurde

**Konzeptionell ähnlich (portiert/angepasst):**
- `ScheduleEvaluator` — Logik im Wesentlichen unverändert.
- `BackupLock` → `FlockLock` — gleiches Verhalten bei flock + Wiederaufnahme
  veralteter Locks.
- Validierung der Laufzeitkonfiguration, Job-Lebenszyklus, Archiv-Sicherheit,
  Pfad-Eingrenzung und Schwärzung von Geheimnissen — Regeln erhalten, neu
  verpackt.

**Vollständig neu entworfen:**
- Backend-UI (Twig-Monolith → Fluid + externe Assets).
- Autorisierung & Audit-Logging (Contao Security/`tl_log` → Ports + TYPO3-Adapter).
- Job-Worker & Befehlsausführung (über Schritte verstreutes Symfony
  Process/`exec`/`shell_exec` → einzelne `CommandExecutorInterface`-Nahtstelle,
  shell-freier `CommandRequest`).
- Backup/Restore (Contao/Symfony-Baum + `contao-console` → TYPO3-Layout +
  Core-APIs).
- Cache/Wartung/Migration (`contao-console`-Aufrufe → dedizierte Ports).
- Zeitplan-Auslöser (Contao-Cron-Hook → TYPO3 Scheduler/Crontab).
- Recovery-Panel (als separates, späteres, sicherheitsgeprüftes Deliverable neu
  aufgebaut).

## Verdrahtung

`Configuration/Services.yaml` nutzt Autowiring/Autokonfiguration, schließt den
reinen `Domain/`-Namespace aus (registriert nur dessen drei
abhängigkeitsfreie Services erneut), macht den Modul-Controller `public` und
bindet jeden Port an seinen Phase-1-Adapter.

## Kompatibilität mit TYPO3 13.4 / 14

Die Extension unterstützt **TYPO3 13.4.9 bis 14.x aus einer gemeinsamen
Codebasis**. Das Ports-and-Adapters-Design macht das kostengünstig: Jede
TYPO3-API-Nutzung ist auf die `Typo3/`-Adapter und den einzelnen
Backend-Controller begrenzt, und jede dort verwendete TYPO3-API (die
array-basierte Backend-Modul-Registrierung, `ModuleTemplateFactory`,
`ModuleTemplate::renderResponse()`, `UriBuilder`, `Environment`,
Backend-User-Globals, `Icons.php`, Symfony DI und die Core-Fluid-ViewHelper)
ist Teil der identischen, stabilen Teilmenge, die sowohl in 13.4 als auch in
14 verfügbar ist. In Phase 1 sind keine Laufzeit-Versionsprüfungen und keine
versionsspezifischen Adapter erforderlich. Siehe `CompatibilityStrategy.md`
und `CompatibilityAudit.md` für die vollständige Analyse.
