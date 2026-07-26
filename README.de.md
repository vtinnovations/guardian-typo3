<!--
  This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.

  @author    V&T Innovations Team
  @license   LGPL-3.0-or-later
  @copyright V&T Innovations 2026 - 2028
-->

# Guardian für TYPO3

*🇬🇧 [English version](README.md)*

**Admin-Cockpit für Updates, Backups & Wiederherstellung für TYPO3 13.4 und 14** –
von [V&T Innovations](https://www.v-t.one).

Guardian wird vom gleichnamigen Contao-5-Bundle auf eine native TYPO3-Erweiterung
portiert, die **TYPO3 13.4.9 bis 14.x** aus einem einzigen Paket unterstützt. Ziel
ist es, eine TYPO3-Installation aktualisierbar und wiederherstellbar zu halten:
Composer-Updates, automatische Backups, Wiederherstellung/Rollback sowie ein
eigenständiges Wiederherstellungspanel, das selbst dann funktioniert, wenn TYPO3
nicht mehr startet.

> ⚠️ **Entwicklungsstatus – Phase 1 (schreibgeschützte Grundlage).**
> Diese Version ist eine **schreibgeschützte Hülle**. Sie führt **keine** Backups,
> Updates, Wiederherstellungen, Löschungen, Migrationen oder Wartungsänderungen
> durch und liefert **keine** Prozessausführung und **kein** Wiederherstellungspanel.
> Jede noch nicht implementierte Operation schlägt ausdrücklich fehl, anstatt einen
> Erfolg vorzutäuschen. Erwarten Sie noch keine destruktiven Fähigkeiten – siehe
> `Documentation/ImplementationRoadmap.md`.

## Voraussetzungen

- TYPO3 **13.4.9 bis 14.x** (`typo3/cms-core: ^13.4.9 || ^14.0`); TYPO3 13.4.9 ist
  die minimal unterstützte Version. Der Composer-Modus wird dringend empfohlen
  (Update-Funktionen setzen ihn voraus)
- PHP **mindestens 8.2** (`~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0`). Das effektive
  Minimum auf einer konkreten Installation ist das, was der installierte
  TYPO3-Core erzwingt – TYPO3 13.4 läuft ab PHP 8.2, TYPO3 14 hebt die Untergrenze
  weiter an –, die Erweiterung selbst unterstützt jedoch den gesamten Bereich
- Ein beschreibbares `var/`-Verzeichnis (Guardian speichert seinen Zustand unter
  `var/guardian/`)

Spätere Phasen benötigen zusätzlich eine PHP-CLI-Binärdatei, `composer.phar` sowie
die Werkzeuge `mysqldump`/`mysql`/`tar` für Backup- und Update-Funktionen.

## Installation (Konzept)

```bash
composer require vtinnovations/guardian-typo3
```

Leeren Sie anschließend im TYPO3-Backend die Caches, damit das neue Backend-Modul
registriert wird. Das **Guardian**-Modul erscheint unter **System** und ist
**ausschließlich für Administratoren** sichtbar.

> Es sind keine Legacy-Installationsschritte erforderlich. Dies ist eine
> Composer-basierte TYPO3-Erweiterung (13.4/14); es gibt keine `ext_emconf.php` und
> kein Contao-artiges Konsolen-Bootstrapping. Siehe
> `Documentation/CompatibilityStrategy.md` für die Begründung, warum
> Composer-Metadaten allein für den unterstützten Installationsmodus ausreichen.

## Verzeichnisstruktur

```
guardian_typo3/
├── composer.json                 typo3-cms-extension, PSR-4 → Classes/
├── Configuration/
│   ├── Services.yaml             DI: Ports → Adapter
│   ├── Icons.php                 Modul-Icon
│   └── Backend/Modules.php       System-Modul nur für Administratoren
├── Classes/
│   ├── Domain/                   reine Value Objects & Regeln (kein TYPO3, keine I/O)
│   ├── Application/              Use-Case-Services + Contract/-Ports
│   ├── Infrastructure/           JSON-Repositories, Uhr, flock, Pfade, verweigernder Executor
│   ├── Typo3/                    TYPO3-Adapter (Umgebung, Auth, Logging, Scheduler)
│   └── Controller/Backend/       Backend-Modul-Controller
├── Resources/
│   ├── Private/{Layouts,Templates,Partials,Language}/   Fluid + XLF
│   └── Public/{Css,JavaScript,Icons}/                   externe Assets
├── Tests/Unit/                   CMS-unabhängige PHPUnit-Tests
└── Documentation/                Audit, Feature-Matrix, Architektur, Sicherheit, Roadmap
```

Der Laufzeitzustand von Guardian liegt unter `var/guardian/` (Laufzeitkonfiguration,
Lizenz-Cache, Zeitplan, Locks und – in späteren Phasen – Jobs, Logs und Backups).

## Sicherheit

- Das Backend-Modul ist **ausschließlich für Administratoren** (`access: admin`),
  zusätzlich abgesichert durch eine Admin-Prüfung im Code.
- Phase 1 besitzt **keine Schreib-Endpunkte** und führt **keinen** externen Prozess
  aus.
- Die gesamte Pfadverarbeitung ist auf `var/guardian/` begrenzt und wird
  symlink-unabhängig validiert; die Archiv-Sicherheits- und
  Geheimnis-Schwärzungsregeln aus dem Contao-Original sind portiert und durch
  Unit-Tests abgesichert.
- Das eigenständige Wiederherstellungspanel wird als **separate, spätere,
  sicherheitskritische Lieferung** behandelt und ist bewusst **nicht** Teil dieser
  Phase.

Siehe `Documentation/SecurityModel.md` für das vollständige Modell.

## Oberfläche

Das Backend-Modul ist eine **originalgetreue Portierung der ursprünglichen
Guardian-Oberfläche**: eine Seite mit den ursprünglichen fünf Reitern –
**Dashboard, Update, Backup, Recovery, Einstellungen** –, die clientseitig
umgeschaltet werden, mit dem ursprünglichen `.updater-*`-Karten-, Tabellen-,
Badge-, Modal- und Job-Runner-Styling sowie dem orangefarbenen Guardian-Branding,
sowohl im hellen als auch im dunklen Backend-Modus. Geplante Backups befinden sich
innerhalb von **Backup**; die Pro-Lizenz, die Wiederherstellungs-E-Mail und die
PHP-CLI-Einstellungen befinden sich innerhalb von **Einstellungen**. Siehe
`Documentation/ContaoUiParityMatrix.md` und
`Documentation/VisualParityChecklist.md`.

## Aktueller Funktionsumfang

- **Aktiv (schreibgeschützt):** Lizenzstatus, Liste der installierten Pakete,
  Backup-Liste, Anzeige von Zeitplan-/Laufzeitkonfiguration, Herkunft des
  Wiederherstellungs-Tokens sowie die Vor-Update-Analyse – allesamt bereitgestellt
  über CSRF-geschützte, ausschließlich für Administratoren zugängliche
  Backend-AJAX-Endpunkte.
- **Mit ausdrücklicher Begründung deaktiviert:** jedes destruktive Bedienelement
  (Backup erstellen/löschen, Updates ausführen/planen, Lizenz aktivieren/entfernen,
  Laufzeiteinstellungen speichern, Token rotieren, E-Mails senden). Diese werden als
  `disabled` dargestellt mit einem Inline-Hinweis, der die Backend-Phase benennt,
  die sie aktivieren wird – nichts täuscht einen Erfolg vor.
- Noch kein ausgehendes HTTP, keine Prozessausführung, keine geplante Ausführung.

## Entwicklung

```bash
composer install
vendor/bin/phpunit -c phpunit.xml.dist   # CMS-unabhängige Unit-Tests
```

> Die Unit-Tests in `Tests/Unit/` decken die CMS-unabhängige Logik ab
> (Konfigurationsvalidierung, Pfadsicherheit, Zeitplan-Berechnung, Lock-Verhalten,
> Lizenzinterpretation, Archivvalidierung, Kommandokonstruktion, Job-Übergänge).
> Sie wurden in der Umgebung, in der dieses Grundgerüst erzeugt wurde, **nicht**
> ausgeführt (dort war keine PHP-/Composer-Laufzeit verfügbar). Siehe
> `Documentation/Testing.md` für den vollständigen Befehlssatz und die
> CI-Matrix für TYPO3 13.4 / 14.

## Geplante CLI (noch nicht implementiert)

Spätere Phasen führen einen TYPO3-Konsolen-Worker-Befehl ein (z. B. den
Hintergrund-Job-Runner) sowie eine Scheduler-Integration für Backups. Diese sind
**geplant** und existieren in Phase 1 nicht.

## Lizenz

LGPL-3.0-or-later · © V&T Innovations
