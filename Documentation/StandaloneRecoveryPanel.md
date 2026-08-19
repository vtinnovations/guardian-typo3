# Eigenständiges Wiederherstellungspanel

Das eigenständige Wiederherstellungspanel ist ein **Opt-in-Notfall-Einstiegspunkt
nur für Pro**, der einem autorisierten Administrator erlaubt, ein
Guardian-Backup wiederherzustellen, **auch wenn das TYPO3-Backend nicht
verfügbar ist**. Es ist standardmäßig deaktiviert und muss explizit über das
Guardian-Backend-Modul (Recovery-Tab) aktiviert und bereitgestellt werden.

## Entwurfsprinzip: eine einzige Restore-Engine

Der öffentliche Einstiegspunkt (`public/<Dateiname>`, Standard
`_guardian-recovery.php`) ist **nur ein schlanker Bootstrap + Controller**. Er
enthält keine eigene Restore-Logik. Die gesamte eigentliche Arbeit wird von
**denselben** Guardian-Recovery-Application-Services erledigt, die auch das
Backend nutzt:

| Belang | Gemeinsam genutzter Service |
| --- | --- |
| Backup-Index + vollständige Verifizierung | `Application\Recovery\BackupCatalog` |
| Schreibgeschützter Preflight | `Application\Recovery\RecoveryPreflight` |
| Gestufte Wiederherstellung, Snapshot, Rollback, Wartung | `Application\Recovery\RestoreService` |
| Sichere Archiv-Extraktion | `Infrastructure\Recovery\ZipBackupArchiveExtractor` |
| DB-Dump / -Import | `Infrastructure\Database\Typo3Database{Dumper,Importer}` |
| Wartungsmarker | `Infrastructure\Maintenance\FileMaintenanceMode` |

`Recovery\Standalone\StandaloneRecoveryKernel` verdrahtet diese Klassen von
Hand vom eigenen Speicherort des Panels aus. Da DB-Dumper/-Importer ihre
Verbindungskonfiguration aus `$GLOBALS['TYPO3_CONF_VARS']` lesen, lädt der
Kernel `config/system/settings.php` vor jeder Wiederherstellung in diese
globale Variable — es ist kein TYPO3-Boot erforderlich.

## Pfadermittlung

Das Panel liegt im öffentlichen Web-Root. Der Kernel leitet ab:

- `publicPath = dirname(__FILE__)`
- `projectPath = dirname(publicPath)`
- `varPath = projectPath . '/var'` → Guardian-Zustand unter
  `var/guardian/`.

## Sicherheitsmodell

- **Standardmäßig deaktiviert.** Ein nicht aktiviertes Panel antwortet wie
  eine fehlende Datei (404).
- **Token.** Erzeugt aus einer kryptografisch sicheren Zufallsquelle und nur
  in nicht wiederherstellbarer Form gespeichert, in einer eingeschränkten
  Datei außerhalb des Web-Roots. Der Klartext wird **einmalig**, bei der
  Erzeugung oder Rotation, angezeigt und niemals persistiert oder geloggt;
  danach zeigt die Oberfläche nur eine maskierte Vorschau. Der Vergleich ist
  zeitkonstant. Ein Token kann alternativ zum gespeicherten auch über die
  Server-Umgebungsvariable `GUARDIAN_RECOVERY_TOKEN` bereitgestellt werden.
- **Sitzungen.** Das PHP-Session-Cookie ist `HttpOnly`, `SameSite=Strict`,
  bei HTTPS `Secure`. Die Session-ID wird nach dem Login regeneriert
  (Schutz vor Session-Fixation). Leerlauf-Timeout 15 Min., absolute
  Lebensdauer 1 Std. Das Rotieren des Tokens macht bestehende Sitzungen
  ungültig.
- **CSRF.** Ein sitzungsgebundenes Token schützt jeden zustandsändernden
  Request.
- **Ratenbegrenzung.** Fehlgeschlagene Versuche werden pro Client gezählt,
  ohne die Adresse in wiederherstellbarer Form zu speichern; 5 Fehlversuche /
  15 Min. → 15 Minuten Sperre; Einträge laufen automatisch ab. Fehlschläge
  liefern eine einzige generische Meldung, die niemals verrät, wie nah ein
  Versuch war.
- **Kein Geheimnis in der ausgelieferten Datei.** Das Token lebt außerhalb
  des Web-Roots; die ausgelieferte Datei enthält selbst kein Geheimnis.
- **Ausfallsichere Fehler.** `display_errors` ist deaktiviert; ein globaler
  Exception-Handler rendert eine generische Meldung — niemals einen
  Stacktrace, Klassennamen oder absoluten Pfad.
- **Response-Header.** `X-Content-Type-Options`, `X-Frame-Options: DENY`,
  `Referrer-Policy: no-referrer`, eine strikte `Content-Security-Policy` und
  `noindex`.

## Deployment-Sicherheit

Das Deployment wird von Guardian verwaltet und:

- schreibt einen **Eigentümersignatur**-Marker
  (`GUARDIAN-RECOVERY-PANEL:MANAGED-ENTRYPOINT`) — Guardian entfernt nur
  Dateien, die diesen tragen. **Eine gleichnamige eigene Datei eines
  Betreibers wird niemals gelöscht oder überschrieben.**
- stellt **atomar** bereit (Temp-Datei + `rename`).
- stellt bei einer Dateinamensänderung zuerst den **neuen** Einstiegspunkt
  bereit und entfernt erst danach den zuvor verwalteten.
- Deaktivieren entfernt den verwalteten Einstiegspunkt und beseitigt die
  Exposition damit vollständig.

## Zustandslayout (außerhalb des Web-Roots)

```
var/guardian/recovery-panel/
  config.json       # Aktivierungsflag und Dateiname des Panels
  token.json        # Authentifizierungsmaterial, nur in nicht wiederherstellbarer Form
  rate-limit.json   # Zähler für Fehlversuche
  audit.log         # Lebenszyklus-Ereignisse von Panel/Token/Login/Recovery (keine Geheimnisse)
  panel.log         # Laufzeit-Log des eigenständigen Panels
```

## Backend-Endpunkte (Administrator + Pro; POST für Schreibvorgänge; CSRF über Route-Token)

Panel-Status, Dateiname, Deployment, Deaktivierung, Token-Erzeugung und
-Rotation, Panel-Test sowie die Operationen
Recovery-Liste/Preflight/Ausführung/Verlauf werden jeweils von einem eigenen
Backend-Endpunkt bedient. Das rohe Token wird nur in der
Erzeugungs-/Rotationsantwort zurückgegeben.

## Aktivierung (Vorgehen für den Betreiber)

1. Guardian → **Recovery**-Tab.
2. **Token erzeugen** → das angezeigte Token in einen Passwortmanager
   kopieren (wird nur einmal angezeigt).
3. Optional den Panel-Dateinamen ändern → **Speichern**.
4. **Panel aktivieren & bereitstellen**.
5. Prüfen, dass die Panel-URL lädt und das Token authentifiziert.
6. Um die Exposition später zu entfernen: **Panel deaktivieren & entfernen**.
