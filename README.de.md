<!--
  This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.

  @author    V&T Innovations Team
  @license   LGPL-3.0-or-later
  @copyright V&T Innovations 2026 - 2028
-->

# Guardian für TYPO3

*🇬🇧 [English version](README.md)*

**Admin-Cockpit für Composer-Updates, Backups, Wiederherstellung und
Erweiterungsverwaltung in TYPO3 13.4 und 14** – von
[V&T Innovations](https://www.v-t.one).

Guardian ist eine native TYPO3-Backend-Erweiterung, die eine TYPO3-Installation
aktualisierbar und wiederherstellbar hält. Aus einem einzigen, ausschließlich für
Administratoren zugänglichen Modul steuert sie Composer-Updates, vollständige und
geplante Backups, die Wiederherstellung im Backend und über ein eigenständiges
Panel sowie die vollständige Erweiterungsverwaltung – jeweils über dieselbe
Sicherheits-Pipeline (verpflichtendes Backup vor jeder Änderung → Wartungsmodus →
Änderung → Verifizierung → automatisches Rollback bei Fehler).

> **Produktionsstatus.** Guardian ist eine voll funktionsfähige Erweiterung. Jeder
> nachfolgend beschriebene Bereich ist durchgängig implementiert – von der
> Backend-Oberfläche bis zur serverseitigen Ausführung. Destruktive Operationen
> laufen in abgekoppelten PHP-Worker-Prozessen, sind durch eine ausdrückliche
> Bestätigung abgesichert, werden von einem verpflichtenden Sicherheits-Backup
> vorbereitet und rollen bei einem Fehler automatisch zurück. Guardian entstand
> ursprünglich als Portierung des Contao-Guardian-Bundles; heute ist es eine
> eigenständige native TYPO3-Erweiterung und wird hier so beschrieben, wie es
> aktuell funktioniert.

## Voraussetzungen

- **TYPO3 13.4.9 bis 14.x** (`typo3/cms-core: ^13.4.9 || ^14.0`). Ein einziges
  Paket unterstützt beide; 13.4.9 ist das Minimum.
- **PHP 8.2+** (`~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0`). Die effektive Untergrenze
  ist das, was der installierte TYPO3-Core erzwingt.
- **Composer-basierte Installation.** Die Update- und Erweiterungsfunktionen
  arbeiten auf `composer.json`/`composer.lock` und setzen den Composer-Modus
  voraus.
- **`ext-json`** (erforderlich). **`ext-zip`** wird zum Erstellen/Lesen von
  Backup-Archiven genutzt; **`ext-pdo`** dient als reiner PHP-Fallback für den
  Datenbank-Dump, wenn `mysqldump` nicht verfügbar ist.
- Ein **beschreibbares `var/`-Verzeichnis** – Guardian speichert seinen gesamten
  Laufzeitzustand unter `var/guardian/`.

### PHP-CLI- und Composer-Konfiguration

Update- und Wiederherstellungsjobs laufen in einem **abgekoppelten
PHP-CLI-Worker**, nicht im Web-Request. Guardian benötigt daher zur Laufzeit:

- eine erreichbare **PHP-CLI-Binärdatei** – automatisch erkannt und unter
  **Einstellungen → PHP-CLI-Einstellungen** konfigurierbar, falls die Erkennung
  fehlschlägt;
- eine erreichbare **`composer.phar`** (oder Composer-Binärdatei) im Projekt;
- die Konsolen-Binärdatei **`vendor/bin/typo3`** (in jeder Composer-basierten
  TYPO3-Installation vorhanden).

Backups nutzen zusätzlich `mysqldump`/`mysql`, sofern verfügbar (mit einem reinen
PHP-PDO-Fallback für den Dump), sowie den `ext-zip`-Archiv-Writer.

### Dateisystem-Berechtigungen

Der PHP-Prozess (Web und CLI) muss **`var/guardian/` lesen und beschreiben**
können und – für Updates/Installationen – auf `composer.json`, `composer.lock`,
`vendor/` und `packages/` des Projekts zugreifen können. Guardian legt seine
privaten Unterverzeichnisse mit restriktiven Berechtigungen an und macht sie
niemals welt-beschreibbar.

## Installation

```bash
composer require vtinnovations/guardian-typo3
```

Anschließend die TYPO3-Caches leeren, damit das Backend-Modul registriert wird:

```bash
vendor/bin/typo3 cache:flush
```

Das **Guardian**-Modul erscheint unter **System** und ist **ausschließlich für
TYPO3-Administratoren** sichtbar. Es gibt keine `ext_emconf.php` und keinen
Legacy-Installationsschritt – die Composer-Metadaten sind maßgeblich.

## Zugriff auf das Backend-Modul

- Registriert mit `access: admin` (nur Administratoren) und zusätzlich im Code
  abgesichert (`assertAdministrator()`) bei jedem Request und jedem
  AJAX-Endpunkt – die Zugriffsgarantie hängt niemals allein von der
  Routing-Konfiguration ab.
- Alle zustandsändernden Endpunkte sind **ausschließlich POST** und tragen das
  CSRF-Route-Token von TYPO3.
- Der Funktionszugriff über „Administrator“ hinaus wird durch die aktive
  Lizenzstufe geregelt (siehe [Lizenz- und Berechtigungsmatrix](#lizenz--und-berechtigungsmatrix)).

## Navigation

Das Modul ist eine einzelne Seite mit sechs clientseitigen Reitern, in dieser
Reihenfolge:

1. **Dashboard**
2. **Update**
3. **Backup**
4. **Recovery** (Wiederherstellung)
5. **Extensions** (Erweiterungen)
6. **Settings** (Einstellungen)

### Dashboard

- Zusammenfassung von Lizenz und Berechtigung (Keine / Free / Pro) mit der Liste
  der freigeschalteten Funktionen sowie einem Aufruf zum Upgrade bzw. zur
  Lizenzeingabe.
- Systeminformationen: erkannte **TYPO3-Version**, **Anzahl installierter
  Pakete**, **Anzahl verfügbarer Backups**.
- Aktueller Betriebsstatus (Leerlaufanzeige).
- Starter für die **Vor-Update-Analyse**: eine schreibgeschützte Umgebungsprüfung
  (Composer-Modus, Composer-Dateien, PHP-Version, Beschreibbarkeit des
  Arbeitsverzeichnisses, PHP-CLI, Datenbankverbindung, Speicherplatz, Lizenz,
  laufender Job, Backup-Fähigkeit).

### Update

- Erkennt die **installierte TYPO3-Version** und führt eine
  **Online-Release-Ermittlung** über den öffentlichen TYPO3-Release-Feed durch:
  das **neueste Release der aktuellen Hauptversion** und die **nächste stabile
  Hauptversion**.
- **Auswahl der Zielversion**, danach ein **Composer-Probelauf**, der die
  **betroffenen Pakete und Erweiterungen** meldet, ohne das Live-Projekt zu
  verändern.
- **Run Live** bleibt deaktiviert, bis eine Zielversion ausgewählt ist und ein
  Probelauf erfolgreich war.
- Ein Live-Update durchläuft die vollständige Sicherheits-Pipeline:
  **verpflichtendes Sicherheits-Backup → Wartungsmodus → `composer update` →
  TYPO3-Extension-Setup / Datenbankschema → Cache-Leerung → Verifizierung der
  resultierenden Installation**, mit **automatischem Rollback** aus dem
  Sicherheits-Backup bei jedem Fehler. Ein manuelles **Rollback** ist ebenfalls
  verfügbar.
- Live-**Fortschritt**, Schrittzustände und Streaming-Logs; eine Liste der
  **letzten Update-Jobs**; sowie das **erneute Öffnen eines abgeschlossenen Jobs**
  zur Einsicht in Endstatus und Logs.

### Backup

- **Manuelle Backups** mit komponentenweiser Auswahl: der stets enthaltene
  Kernsatz (`composer.json` + `composer.lock` + Datenbank-Dump), **fileadmin**,
  lokale **`packages/`**, generierte Extension-Assets und das
  **`vendor/`**-Verzeichnis.
- **Datenbank-Dump** über `mysqldump` mit einem reinen PHP-PDO-Fallback.
- Korrekte Behandlung von **Composer-Path-Repository-Symlinks**, damit lokale
  Pakete als echte Dateien erfasst werden.
- Jedes Archiv trägt ein **Manifest und Prüfsummen**; Backups werden vor der
  Wiederherstellung **validiert**. **Aufbewahrungsgrenzen** werden pro Profil
  durchgesetzt.
- **Geplante Backups** (Mini-/Voll-Profile mit Frequenz-, Zeit- und
  Wochentags-/Tagesregeln) werden hier konfiguriert und vom Konsolenbefehl
  `guardian:backup:run-due` ausgeführt (siehe [Deployment](#deployment)).
- **Sicherheits-Backups vor Updates** werden von den Update- und
  Extensions-Pipelines vor jeder Änderung automatisch erstellt.
- Backups auflisten, Details einsehen, herunterladen und löschen.

### Recovery (Wiederherstellung)

Zwei unabhängige Wiederherstellungswege:

- **Backend-Wiederherstellung** (innerhalb von TYPO3): Backup-Ermittlung, ein
  **verpflichtender Preflight und Probelauf**, Komponentenauswahl, Staging,
  **Wiederherstellung lokaler Pakete**, ein **sicherer Vendor-Neuaufbau** aus
  `composer.lock` in isoliertem Staging mit **atomarem Vendor-Wechsel**,
  **Datenbank-Wiederherstellung**, Wartungsmodus, ein **Transaktionsjournal**,
  **Rollback**, **Erkennung und Rollback unterbrochener Wiederherstellungen**
  sowie **Verifizierung nach der Wiederherstellung**.
- **Eigenständiges Wiederherstellungspanel**: ein einzelner, in sich
  geschlossener PHP-Einstiegspunkt, den Guardian in das öffentliche Web-Root
  ausliefert und der ein Guardian-Backup **auch dann wiederherstellt, wenn TYPO3
  nicht mehr startet**. Es authentifiziert sich mit dem **Wiederherstellungs-Token**
  (gehasht gespeichert oder über die Umgebungsvariable `GUARDIAN_RECOVERY_TOKEN`
  bereitgestellt), ist ratenbegrenzt und nutzt dieselbe
  Wiederherstellungs-Engine wie das Backend. Dateiname, Bereitstellung und Token
  werden unter Recovery/Einstellungen verwaltet.
- **Wiederherstellungs-E-Mail-Benachrichtigungen**: Vor einem Live-Update kann
  Guardian die Wiederherstellungs-URL und das Zugriffstoken an eine konfigurierte
  Adresse senden, über die `MailerInterface` von TYPO3.

### Extensions (Erweiterungen)

Vollständige Composer-basierte Erweiterungsverwaltung (Pro):

- **Auflistung installierter Erweiterungen/Pakete** mit **Klassifizierung**
  (TYPO3-Core, System-Extension, Drittanbieter-Extension, lokale Extension,
  Composer-Bibliothek) und **Update-Ermittlung**.
- **Paketweises Update**, **Aktivieren**, **Deaktivieren** und **Entfernen**,
  jeweils mit Probelauf, Bestätigung, Sicherheits-Backup und Rollback.
- **Guardian-Selbstverwaltung**: ein verzögertes **Selbst-Deaktivieren** und ein
  kontrolliertes **Selbst-Entfernen**, beide mit eingetippten
  Bestätigungsphrasen; Guardian löscht sein eigenes Paketverzeichnis niemals
  implizit.
- **TER**: das TYPO3 Extension Repository durchsuchen, **Kompatibilitätsangaben**
  für die laufende TYPO3-Version anzeigen und über einen Ablauf
  **Probelauf → Installation** installieren.
- **Eigener ZIP-Upload**: in einen **privaten Staging-Bereich** hochgeladen, durch
  eine **ZIP-Sicherheitsprüfung** und eine **Erkennung der Extension-Metadaten**
  geführt, danach ein Ablauf **Probelauf → Installation**.
- Lokale Installationen registrieren eine **exakte
  Path-Repository-Versionszuordnung** und **von Guardian verwaltete
  Eigentümer-Metadaten**, sodass ein späteres **Entfernen das zugehörige
  Quellverzeichnis löscht** (über eine Quarantäne) und eine zuvor entfernte
  hochgeladene Erweiterung **sauber erneut hochgeladen und installiert** werden
  kann (Erkennung verwaister Verzeichnisse).
- Durchgehend Live-**Fortschritt, strukturierte Fehlerberichte und Job-Logs**.

### Settings (Einstellungen)

- **Lizenz**: eine Lizenz aktivieren, **Update License** und entfernen. Die
  Validierung erfolgt **lokal** aus dem gespeicherten Dokument (Ausstellungs-,
  Start-, Ablauf- und **Lebenslang**-Daten), sodass eine geprüfte Lizenz offline
  weiterarbeitet, bis sie tatsächlich abläuft. **Free-/Pro-Berechtigungen**, ein
  **Free-Fallback bei abgelaufener Pro-Lizenz**, eine **MD5-Speicher-Integritäts**-Prüfung
  und eine optionale **Ed25519-Signatur**-Ebene werden angewendet.
- **PHP-CLI-Einstellungen**: den Pfad zur PHP-CLI-Binärdatei automatisch erkennen,
  testen und speichern.
- **Wiederherstellungs-E-Mail**: Empfänger/Absender konfigurieren und eine
  **Test-E-Mail senden** (über `MailerInterface`).
- **Konfiguration des eigenständigen Wiederherstellungspanels**: Panel-Dateiname,
  Bereitstellung und Token.

## Lizenz- und Berechtigungsmatrix

Der Zugriff wird **serverseitig** an jedem Endpunkt durchgesetzt
(Administrator-Gate → Lizenz-Gate). „Free“ bedeutet eine beliebige gültige
Lizenz; „Pro“ bedeutet eine gültige `pro`-Lizenz.

| Funktion | Zugriff |
| --- | --- |
| Manuelles Backup | **Free und Pro** |
| Geplantes Backup | **Nur Pro** |
| Update | **Nur Pro** |
| Extensions | **Nur Pro** |
| Recovery (Backend) | **Nur Pro** |
| Eigenständige Wiederherstellung (bereitstellen & verwalten) | **Nur Pro** |
| Lizenzaktivierung / Update / Entfernen (Einstellungen) | **Verfügbar** (Administrator) |
| Vor-Update-Analyse (Dashboard) | **Verfügbar** (Administrator) |

Effektiver Zugriff je Lizenzzustand:

| Lizenzzustand | Manuelles Backup | Geplantes Backup | Update | Extensions | Recovery | Eigenständige Wiederherstellung |
| --- | --- | --- | --- | --- | --- | --- |
| Keine Lizenz | Nicht zutreffend | Nicht zutreffend | Nicht zutreffend | Nicht zutreffend | Nicht zutreffend | Nicht zutreffend |
| Aktive **Free** | Verfügbar | Nicht zutreffend | Nicht zutreffend | Nicht zutreffend | Nicht zutreffend | Nicht zutreffend |
| Aktive **Pro** | Verfügbar | Verfügbar | Verfügbar | Verfügbar | Verfügbar | Verfügbar |
| Abgelaufene **Pro** mit Free-Fallback | Verfügbar (Free-Fallback) | Nicht zutreffend | Nicht zutreffend | Nicht zutreffend | Nicht zutreffend | Nicht zutreffend |
| Abgelaufene Lizenz ohne Fallback | Nicht zutreffend | Nicht zutreffend | Nicht zutreffend | Nicht zutreffend | Nicht zutreffend | Nicht zutreffend |
| Fehlerhaft / ungültig / Integritäts- oder Signaturfehler / Domain-Konflikt | Nicht zutreffend | Nicht zutreffend | Nicht zutreffend | Nicht zutreffend | Nicht zutreffend | Nicht zutreffend |

Ohne **gültige Lizenz** sind nur Dashboard und Einstellungen nutzbar (damit eine
Lizenz eingegeben werden kann). Verwendete Statusbezeichnungen: **Verfügbar**,
**Nur Pro**, **Free und Pro**, **Bedingt**, **Nicht zutreffend**.

## Sicherheitsarchitektur

- **Nur für Administratoren** zugängliches Modul und Endpunkte (`access: admin` +
  Prüfung im Code); **POST + CSRF-Token** bei jeder Mutation.
- **Pfad-Eingrenzung**: der gesamte Guardian-Zustand ist auf `var/guardian/`
  begrenzt und wird symlink-unabhängig validiert; Uploads sind auf ein privates
  Staging-Verzeichnis eingegrenzt.
- **Keine Shell-String-Ausführung**: externe Prozesse laufen über
  `symfony/process` mit Argument-Arrays – niemals
  `exec()`/`shell_exec()`/`system()`/Backticks.
- **ZIP-Sicherheitsprüfung** (Path-Traversal, Symlinks, Anzahl/Größe der Einträge
  und Prüfung auf Dekomprimierungsbomben) bei jedem hochgeladenen Archiv.
- **Schwärzung von Geheimnissen**: Logs und API-Antworten geben niemals
  Lizenzschlüssel, Signaturen, erwartete Integritäts-Digests,
  Wiederherstellungs-Token, Transport-Zugangsdaten, DSNs, Stacktraces oder
  absolute Installationspfade preis.
- Der **Lizenzspeicher** trägt einen MD5-Integritätsindikator und eine optionale
  asymmetrische Signatur; das Token des eigenständigen Wiederherstellungspanels
  wird gehasht gespeichert.

Siehe [`Documentation/SecurityModel.md`](Documentation/SecurityModel.md),
[`Documentation/LicensingImplementation.md`](Documentation/LicensingImplementation.md),
[`Documentation/LicensingSecurity.md`](Documentation/LicensingSecurity.md).

### Sicherheit bei Update, Backup und Wiederherstellung

- **Update-Sicherheit**: verpflichtendes Backup vor dem Update, Wartungsmodus,
  isolierter Composer-Probelauf, Verifizierung des Ergebnisses und
  **automatisches Rollback** aus dem Sicherheits-Backup, falls ein Schritt
  fehlschlägt. Siehe
  [`Documentation/UpdateImplementation.md`](Documentation/UpdateImplementation.md).
- **Backup-Sicherheit**: Manifeste + Prüfsummen, Validierung vor der
  Wiederherstellung, Durchsetzung der Aufbewahrung sowie korrekte Behandlung von
  Symlinks/Vendor/lokalen Paketen.
- **Wiederherstellungs-Sicherheit**: verpflichtender Probelauf, atomarer
  Vendor-Wechsel, ein Transaktionsjournal, Erkennung/Rollback unterbrochener
  Wiederherstellungen und Verifizierung nach der Wiederherstellung. Siehe
  [`Documentation/RecoverySafety.md`](Documentation/RecoverySafety.md) und
  [`Documentation/StandaloneRecoveryPanel.md`](Documentation/StandaloneRecoveryPanel.md).
- **Sicherheit bei der Extension-Installation**: privates Staging, ZIP-Prüfung,
  Probelauf, verwaltete Eigentümer-Metadaten sowie sicheres Entfernen/erneutes
  Installieren verwalteter Quellverzeichnisse.

## Laufzeitverzeichnisse

Guardian hält **den gesamten** Zustand unter `var/guardian/`, darunter: den
Lizenzspeicher (`license.json`), die Laufzeitkonfiguration, Backup-Zeitpläne,
Prozess-Locks, Update-Jobs und deren Logs, erstellte **Backups**, das
Wiederherstellungs-Staging und das Transaktionsjournal, das
Extension-**Upload-Staging** sowie die **Quarantäne** entfernter verwalteter
Verzeichnisse und das Token des eigenständigen Wiederherstellungspanels. Außerhalb
von `var/guardian/` wird nichts geschrieben – außer den Operationen, die der
Administrator ausdrücklich auslöst (Composer-Änderungen, die ausgelieferte
Recovery-Panel-Datei im Web-Root und wiederhergestellte Projektdateien).

## Externe V-T.ONE-Kommunikation

Guardian kontaktiert genau drei V-T.ONE-Endpunkte, über TLS, und verhält sich
ausfallsicher, falls einer nicht erreichbar ist:

- **Lizenzprüfung** – `https://www.v-t.one/api/v1/verify`
- **Lizenzaktualisierung („Update License“)** – `https://www.v-t.one/rest/api/v1/guardian-license-updater`
- **Invocation-Signal** – `https://www.v-t.one/rest/api/v1/log-envoke`
  (Fire-and-forget; überträgt **ausschließlich** den Projektbezeichner und die
  normalisierte Domain; blockiert niemals den Request oder die
  Lizenzentscheidung)

Es findet kein weiteres ausgehendes HTTP statt, außer den Abfragen des TYPO3
Extension Repository / von Packagist im Reiter Extensions.

## Protokollierung und Schwärzung von Geheimnissen

Betriebsausgaben werden in die Job-Logs von Guardian und das TYPO3-System-Log
geschrieben. Alle Logzeilen und AJAX-Payloads durchlaufen vor dem Verlassen des
Servers eine Schwärzung von Geheimnissen: Lizenzschlüssel, Signaturen,
Integritäts-Digests, Wiederherstellungs-Token, Mail-Transport-DSNs/-Zugangsdaten
und absolute Pfade werden niemals ausgegeben.

## Deployment

```bash
# 1. Erweiterung installieren / aktualisieren
composer require vtinnovations/guardian-typo3

# 2. Autoloader neu erzeugen (Produktion)
composer dump-autoload -o

# 3. Modul registrieren/aktualisieren und Extension-Setup anwenden
vendor/bin/typo3 extension:setup
```

Geplante Backups erfordern einen **externen Auslöser**, der den Konsolenbefehl
periodisch aufruft – einen echten Cron-Eintrag oder eine TYPO3-Scheduler-Aufgabe
vom Typ *„Konsolenbefehl ausführen“*. Guardian registriert **keine**
Scheduler-Aufgabe automatisch.

```cron
*/5 * * * * /usr/bin/php /path/to/project/vendor/bin/typo3 guardian:backup:run-due
```

Registrierte Konsolenbefehle:

- `guardian:backup:run-due` – aktuell fällige geplante Backups ausführen (Cron/Scheduler).
- `guardian:update:run` – interner abgekoppelter Update-/Extensions-Worker (von Guardian gestartet; nicht manuell auszuführen).
- `guardian:license:digest` – Entwicklerwerkzeug zum Fixieren des
  Speicher-Integritäts-Digests für eine eingefrorene Lizenzdatei.

## Cache leeren

```bash
vendor/bin/typo3 cache:flush
```

Nach dem Ausliefern aktualisierter Frontend-Assets das Backend hart neu laden,
damit die aktualisierten `guardian.js`/`guardian.css` geladen werden.

## Tests

```bash
composer install
vendor/bin/phpunit -c phpunit.xml.dist
```

Die Suite unter `Tests/Unit/` deckt die CMS-unabhängige Logik ab
(Lizenzinterpretation und Speicher-Schema, Pfadsicherheit, Archivvalidierung,
Zeitplan-Berechnung, Lock-Verhalten, Konstruktion von Composer-Befehlen,
Paketklassifizierung, Job-Übergänge, verwaltete Eigentümerschaft und Entfernung).
Siehe [`Documentation/Testing.md`](Documentation/Testing.md) für den vollständigen
Befehlssatz.

## Bekannte Einschränkungen

- Der **Composer-Modus ist erforderlich** für Update und Extensions; auf einer
  Nicht-Composer-Installation können diese Reiter nicht arbeiten.
- **Geplante Backups benötigen einen externen Auslöser** (echter Cron oder eine
  TYPO3-Scheduler-Aufgabe „Konsolenbefehl ausführen“) – es gibt keine automatisch
  registrierte Scheduler-Aufgabe.
- **Update-/Wiederherstellungs-Worker benötigen eine PHP-CLI-Binärdatei und eine
  erreichbare `composer.phar`**; konfigurieren Sie den PHP-CLI-Pfad in den
  **Einstellungen**, falls die automatische Erkennung fehlschlägt.
- Die **Backend-Wiederherstellung läuft innerhalb von TYPO3.** Startet TYPO3 nicht
  mehr, verwenden Sie das **eigenständige Wiederherstellungspanel**.
- Die Unit-Tests sind CMS-unabhängig; eine vollständige funktionale
  TYPO3-Abdeckung hängt von den PHP-/Composer-/Datenbank-Werkzeugen der
  Zielumgebung ab.

## Lizenz und Copyright

LGPL-3.0-or-later · © 2026–2028 V&T Innovations. Siehe [`LICENSE`](LICENSE).
