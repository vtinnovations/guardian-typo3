# Update-Implementierung

Guardians Update-Tab führt ein echtes, im Hintergrund laufendes
Composer-Update eines Composer-Modus-TYPO3-13.4/14-Projekts durch, mit
verpflichtendem Sicherheits-Backup, Wartungsmodus, Datenbankschema-Update,
Cache-Leerung, Verifizierung und Rollback — unter Wiederverwendung von
Guardians bestehenden Subsystemen für Backup, Recovery, Job, Lock, Lizenz,
Logging, Laufzeitkonfiguration und Benachrichtigung. Es gibt **einen**
Update-Workflow und **keine** doppelte Restore-Engine.

## Ablauf

```
Backend (AJAX, Admin + Pro)                 CLI-Worker (guardian:update:run <id>)
──────────────────────────                  ───────────────────────────────────
analyse ─ PreUpdateAnalysis (schreibgeschützt)
updateCheck ─ composer outdated (JSON)
updateDryRun ─┐                             UpdateJobRunner:
updateStart ──┴─ UpdateService.create()      1 safety_backup   (BackupService)
   → validieren + Job speichern (queued)     2 maintenance_on  (MaintenanceMode)
   → Log zurücksetzen                        3 composer         (Symfony Process, argv)
   → abgekoppelten Worker starten ───────▶   4 database_schema  (typo3 database:updateschema)
updateJobStatus / updateJobLog (Polling)     5 cache_clear      (typo3 cache:flush)
updateJobs / updateJobDetails                6 verify           (composer.json/lock, autoload, Version)
updateRollback ─ RestoreService              7 maintenance_off  (vorherigen Zustand wiederherstellen)
```

Der Browser *startet* einen Job nur und pollt; ein langes Update läuft
niemals innerhalb eines Web-Requests.

## Update-Modi und exakte Composer-Befehle

Composer wird immer als `<php-cli> <composer.phar> …` aufgerufen (niemals ein
Shell-Wrapper), damit dessen Plattformprüfungen zur Runtime der Site passen.
Basis-Flags `[B]` = `--no-interaction --no-progress --no-scripts`
(Post-Update-Scripts werden durch die expliziten Schema- + Cache-Schritte
ersetzt).

| Modus | Befehl | Begründung |
| --- | --- | --- |
| **Vollständig** | `composer update [B] --with-all-dependencies` | Alles aktualisieren, was `composer.json` erlaubt, einschließlich transitiver Abhängigkeiten. |
| **Konservativ** | `composer update [B] --prefer-stable` | **Kein** `--with-all-dependencies`, sodass Composer die Bewegung transitiver Abhängigkeiten vermeidet — echte minimale Bewegung, kein umetikettiertes vollständiges Update. |
| **Selektiv** | `composer update <pkg…> [B] --with-dependencies` | Nur die gewählten (serverseitig validierten) Pakete plus deren eigene Abhängigkeiten. |
| **Probelauf** | der Befehl des gewählten Modus **+ `--dry-run`** | Löst auf und meldet geplante Änderungen; ändert nichts. |

`--ignore-platform-req=ext-*`-Flags werden nur für Extensions hinzugefügt, die
das Projekt benötigt, die der CLI-Runtime aber fehlen, und jedes Flag wird vor
der Verwendung erneut gegen `^--ignore-platform-req=ext-[a-z0-9_]+$`
validiert. Die Befehlskonstruktion liegt in der reinen, unit-getesteten
`ComposerCommandFactory`; Paketnamen werden von `PackageName` validiert
(Composer-Syntax, kein führender Bindestrich), sodass eine
Browser-Eingabe niemals ein Flag oder ein Shell-Fragment einschleusen kann.
Jeder Befehl ist ein argv-Array, das **ohne Shell** von
`SymfonyProcessCommandExecutor` ausgeführt wird.

## Online-Update-Prüfung

`PackageUpdateChecker` führt `composer outdated --direct --no-interaction
--format=json` aus, führt das Ergebnis mit
`vendor/composer/installed.json` zusammen und klassifiziert jedes Paket mit
einem sprachneutralen `PackageStatus` (`current`, `patch_available`,
`minor_available`, `major_available`, `abandoned`, `unknown`, `error`). Es ist
schreibgeschützt. Fehlschläge werden klassifiziert (`network_error`,
`auth_error`, `repository_error`, `resolution_error`,
`composer_unavailable`, …) und legen niemals Zugangsdaten oder Tokens offen.

## Sicherheits-Backup (verpflichtend)

Schritt 1 nutzt `BackupService::create()` erneut, um Composer-Dateien +
Datenbank + Konfiguration + lokale Pakete + Templates (+ `vendor/`, falls
ausgewählt) zu sichern. Schlägt dies fehl, bricht der Runner ab, **bevor**
der Wartungsmodus aktiviert oder Composer ausgeführt wird. Die Snapshot-ID
wird für den Rollback auf dem Job gespeichert.

## Wartung, Schema, Cache (TYPO3 13.4 + 14)

- Die Wartung nutzt die gemeinsame `MaintenanceModeInterface`; der vorherige
  Zustand wird erkannt und anschließend wiederhergestellt. Bei einem
  Fehlschlag bleibt sie während des Rollbacks EIN.
- Schema: `vendor/bin/typo3 database:updateschema "*.add,*.change"
  --no-interaction` — nur additive/sichere Änderungen; destruktive Änderungen
  werden niemals automatisch angewendet.
- Cache: `vendor/bin/typo3 cache:flush`. Beide Befehle sind in 13.4 und 14
  stabil; `Typo3ConsoleCommands` ist der einzige Adapter, der zu ändern wäre,
  sollte sich das jemals unterscheiden. Ein Fehlschlag beim Cache-Leeren ist
  eine **Warnung**, kein harter Fehlschlag.

## Verifizierung, Fehlerbehandlung, Rollback

Die Verifizierung prüft, dass `composer.json`/`composer.lock` gültiges JSON
sind, dass `vendor/autoload.php` existiert und dass die TYPO3-Version
erkennbar ist. Bei jedem Fehlschlag, nachdem Composer den Baum möglicherweise
verändert hat, hält der Runner die Wartung EIN und ruft den **gemeinsamen**
`RestoreService` auf, um aus dem Sicherheits-Snapshot zurückzurollen
(`createSnapshot=false`), und stellt den vorherigen Wartungszustand nur dann
wieder her, wenn der Rollback erfolgreich war. War `vendor/` nicht im
Snapshot enthalten, erklärt das Log, dass ein kontrolliertes
`composer install` nötig sein könnte, um die wiederhergestellte
Lock-Datei zu erfüllen. Fehlschläge tragen stabile Codes (`start_failed`,
`not_confirmed`, `rollback_failed`, plus die Fehlercodes der
Online-Prüfung).

## Jobs, Fortschritt, Logs

`UpdateJobStore` persistiert einen einzelnen aktiven Job
(`var/guardian/update/job.json`) sowie ein Archiv
(`var/guardian/update/jobs/<id>.json`). `UpdateJobLog` ist ein
Append-only-JSON-Zeilen-Log mit Offset-Lesezugriff und den geprüften
Mustern zur Schwärzung von Geheimnissen (Passwörter, `-p…`, Tokens,
DSN-Zugangsdaten). Der Browser pollt Status + Log per Byte-Offset und setzt
das Polling nach einem Reload fort.

## Sperren / Nebenläufigkeit

Nur ein aktiver Update-Job ist erlaubt (durch den Store erzwungen; veraltete
Jobs werden bereinigt). Der `BackupService` und `RestoreService` des Workers
erwerben ihre eigenen Operationssperren, sodass sich Backup/Recovery nicht mit
dem Snapshot/Rollback eines Updates überschneiden können.

## Sicherheit

Administrator + Pro + TYPO3-Request-Token bei jedem Endpunkt; POST für
Zustandsänderungen; Paketnamen-Validierung; nur argv-Ausführung (kein
`exec`/`shell_exec`/Backticks/Konkatenation) außer beim einzigen Starter des
abgekoppelten Workers, der ausschließlich eine strikte
`YYYYMMDD-HHMMSS-xxxxxxxx`-Job-ID interpoliert; Timeout + Idle-Timeout bei
jedem Prozess; Schwärzung von Geheimnissen in Logs; keine Zugangsdaten oder
Stacktraces in JSON; verpflichtendes Sicherheits-Backup; Erhalt des
Wartungszustands; kein vorgetäuschter Erfolg.

## Deployment / Betrieb

```bash
composer dumpautoload
vendor/bin/typo3 cache:flush           # DI-Container nach Änderung an Services.yaml neu aufbauen
```

Anforderungen auf dem Server:
- Composer-Modus-TYPO3-Projekt mit beschreibbarem `vendor/` und `var/`.
- Eine echte `composer.phar` (Projekt-Root oder ein konfigurierter Pfad) —
  ein Shell-Wrapper-`composer` wird absichtlich nicht verwendet.
- Eine PHP-**CLI**-Binärdatei (konfiguriert in den Guardian-Einstellungen
  oder automatisch erkannt).
- `proc_open` aktiviert (Symfony Process). Den Worker/die Site als
  Projektbesitzer ausführen, **nicht** als root.

## Manueller Laufzeittest

1. Guardian → Update → **Analyse ausführen** (keine blockierenden Fehler).
2. **Online prüfen** → Status werden befüllt; **Pakete neu laden**
   funktioniert.
3. **Probelauf** → Job läuft, Log streamt geplante Änderungen, nichts ändert
   sich auf dem Datenträger.
4. **Update starten** (Bestätigung ankreuzen) → Sicherheits-Backup →
   Wartung → Composer → Schema → Cache → Verifizierung → wieder online;
   Fortschritt + Schritte + Log aktualisieren sich live.
5. Seite während des Laufs neu laden → Polling wird fortgesetzt.
6. **Letzte Update-Jobs** listet den abgeschlossenen Job.
7. Einen Fehlschlag erzwingen (z. B. eine unmögliche selektive Auswahl) →
   präzise Fehlermeldung; hat sich der Baum verändert, läuft der Rollback
   und die Wartung wird behandelt; **Zurückrollen** wird für einen
   fehlgeschlagenen Job mit Snapshot angeboten.

## Einschränkungen

- Ein Rollback ohne `vendor/` im Snapshot stellt Composer-Dateien + DB +
  Konfiguration wieder her, benötigt aber unter Umständen ein kontrolliertes
  `composer install`, um `vendor/` neu aufzubauen.
- Der Datenbankschema-Schritt wendet nur additive/sichere Änderungen an;
  destruktive Schemaänderungen müssen manuell geprüft und angewendet werden.
