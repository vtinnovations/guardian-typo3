<!--
  This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.

  @author    V&T Innovations Team
  @license   LGPL-3.0-or-later
  @copyright V&T Innovations 2026 - 2028
-->

# Sicherheitsmodell — Guardian für TYPO3

Guardian ist ein hochprivilegiertes Administrationswerkzeug: Es kann Composer
ausführen, Datenbanken dumpen und wiederherstellen, Projektdateien schreiben
und löschen sowie den Wartungsmodus umschalten. Sein Sicherheitsmodell ist
daher zentral für das Produkt. Dieses Dokument beschreibt, auf einem für
Administratoren und Sicherheitsprüfer angemessenen Niveau, die Kontrollen, die
Guardian im Produktivbetrieb durchsetzt. Es lässt absichtlich interne
Implementierungsdetails aus, die für den sicheren Betrieb oder die sichere
Beurteilung von Guardian nicht nötig sind.

## 1. Umfang und Sicherheitsziele

Guardians Sicherheitskontrollen sollen sicherstellen, dass nur autorisierte
Administratoren hochwirksame Operationen auslösen können, dass jede solche
Operation reversibel ist oder sicher fehlschlägt, dass das System nicht über
seine vorgesehenen Grenzen hinaus getrieben werden kann und dass kein Geheimnis
gegenüber einem Browser, einem Log oder einem unprivilegierten Benutzer
offengelegt wird. Lizenzierung und Berechtigungsdurchsetzung werden als
Sicherheitsbelang behandelt und serverseitig durchgeführt.

Die Ziele sind:

- die gesamte Guardian-Funktionalität auf TYPO3-Backend-Administratoren zu
  beschränken;
- jeden zustandsändernden Request gegen Fälschung zu schützen;
- Lizenzberechtigungen auf dem Server durchzusetzen, nicht im Browser;
- externe Prozesse ohne jede Shell-Interpretation auszuführen;
- jede Guardian-Aktivität auf klar definierte Dateisystemgrenzen zu begrenzen;
- destruktive Operationen wiederherstellbar zu machen, mit automatischem
  Rollback bei Fehlschlag;
- Geheimnisse aus Antworten, Logs und Prozesslisten herauszuhalten.

## 2. Zugriffskontrolle

- **Nur Administratoren.** Das Backend-Modul ist so registriert, dass TYPO3
  den Zugriff ausschließlich Backend-Administratoren gewährt, und dies wird im
  Anwendungscode bei jedem Request und jedem AJAX-Endpunkt erneut geprüft. Die
  Zugriffsgarantie hängt niemals allein von der Routing-Konfiguration ab.
- **Einheitliche Durchsetzung.** Jeder Endpunkt prüft unabhängig erneut den
  Administratorstatus; kein Endpunkt geht davon aus, dass das Erreichen des
  Endpunkts bereits eine Autorisierung bedeutet.
- **Berechtigungs-Gate.** Über die Administratorprüfung hinaus wird der
  Funktionszugriff durch die aktive Lizenzstufe geregelt (siehe §4).
  Schreibgeschützte Statusansichten bleiben verfügbar, damit ein
  Administrator jederzeit den aktuellen Zustand sehen und eine Lizenz
  aktivieren kann.
- **Versionsneutral.** Das Autorisierungsverhalten ist auf den unterstützten
  TYPO3-Releases identisch und erfordert keine versionsspezifische
  Verzweigung.

## 3. Request-Schutz

- **CSRF-Schutz.** Jeder zustandsändernde Endpunkt validiert zusätzlich zur
  Administratorprüfung das eingebaute Backend-Request-/CSRF-Token von TYPO3.
  Backend-AJAX-URLs werden von TYPO3 mit eingebettetem Token erzeugt.
- **Nur POST für Mutationen.** Alle zustandsändernden Operationen sind
  ausschließlich über POST erreichbar; schreibgeschützte Abfragen verwenden
  nebenwirkungsfreie Requests.
- **Eingabevalidierung.** Request-Payloads werden validiert und normalisiert,
  bevor irgendeine Datenträger- oder Prozessinteraktion erfolgt; fehlerhafte
  Eingaben werden mit einem sicheren, generischen Fehler zurückgewiesen.

## 4. Lizenz- und Berechtigungssicherheit

Guardian benötigt eine aktivierte V-T.ONE-Lizenz, und die Lizenzierung wird
als Sicherheitsgrenze durchgesetzt. Dieser Abschnitt nennt die Eigenschaften,
auf die sich Administratoren und Prüfer verlassen können. Wie diese
Eigenschaften implementiert sind und an welcher Stelle im Produkt, wird
bewusst nicht beschrieben.

- **Serverseitige Durchsetzung.** Berechtigungsentscheidungen werden auf dem
  Server getroffen, und sowohl Free- als auch Pro-Funktionen werden dort
  durchgesetzt. Jeder Oberflächenzustand, der eine Funktion zu sperren oder
  freizuschalten scheint, ist nur eine Komfortanzeige und wird niemals für die
  Zugriffskontrolle vertraut.
- **Private Speicherung.** Lizenzdaten werden an einem privaten
  Laufzeit-Speicherort außerhalb des öffentlichen Web-Roots mit restriktiven
  Dateiberechtigungen gespeichert und niemals an den Browser ausgeliefert.
- **Authentifizierte, integritätsgeschützte Lizenzdaten.** Lizenzdaten werden
  vor ihrer Verwendung auf Echtheit und Integrität geprüft, sodass eine
  veränderte, handgeschriebene oder ausgetauschte Lizenz nicht akzeptiert
  wird. Die Verifikation stützt sich auf Material, das nur der Hersteller
  erzeugen kann; das ausgelieferte Produkt kann eine Lizenz prüfen, aber keine
  ausstellen.
- **Lokale Routineprüfungen.** Gewöhnliche Berechtigungsprüfungen erfolgen
  lokal und erfordern keinen Netzwerkzugriff, sodass der normale Betrieb
  offline fortgesetzt wird, solange die Lizenz gültig ist.
- **Fernkontakt nur bei Bedarf.** Guardian kontaktiert V-T.ONE bei der
  Aktivierung, bei einer explizit vom Administrator ausgelösten Aktualisierung
  und wenn V-T.ONE ein autorisiertes Lizenz-Update an die Installation sendet.
- **Atomare Updates.** Ein Lizenz-Update wird atomar angewendet: Entweder
  ersetzt die vollständige neue Lizenz die vorherige, oder die vorherige
  bleibt erhalten. Ein fehlgeschlagenes oder nicht erreichbares Update
  widerruft niemals eine aktuell gültige Lizenz, und ein Update kann eine
  Installation nicht auf eine ältere Lizenz zurückstufen, als sie bereits
  besitzt.
- **Fail-restricted, nicht fail-open und nicht global fail-closed.** Schlägt
  die Lizenzvalidierung fehl, werden nur die eingeschränkten (lizenzierten)
  Guardian-Funktionen deaktiviert; der Administrator behält Zugriff auf
  Status- und Lizenzverwaltungsansichten, damit die Situation korrigiert
  werden kann. Bei einem Fehlschlag wird nichts gelöscht oder beschädigt.
- **Free- und Pro-Berechtigungen.** Beide Stufen erfordern eine aktivierte
  Lizenz und werden auf dem Server für jede geschützte Operation durchgesetzt,
  einschließlich Operationen, die über die Kommandozeile, den Scheduler und
  Hintergrund-Worker erreicht werden.
- **Installationsbindung.** Eine Lizenz autorisiert bestimmte Hostnamen, und
  die Berechtigung erfordert zusätzlich, dass einer davon für diese
  Installation in der TYPO3-Site-Konfiguration konfiguriert ist. Das Kopieren
  von Lizenzdaten auf eine andere Installation überträgt die Berechtigung
  nicht mit.
- **Autorisierter Fallback.** Eine abgelaufene Pro-Lizenz behält den
  Free-Funktionsumfang nur, wenn die Lizenz selbst das erlaubt; andernfalls
  entzieht der Ablauf den Zugriff auf jede eingeschränkte Funktion. Es wird
  niemals lokal ein lizenzierter Zustand erzeugt.
- **Keine Geheimnis-Offenlegung.** Der vollständige Lizenzschlüssel und jedes
  Authentifizierungsmaterial werden niemals an den Browser gesendet, im
  clientseitigen Zustand eingebettet oder in gewöhnliche Logs geschrieben.

## 5. Prozessausführung

- **Keine Shell-Interpretation.** Externe Befehle (zum Beispiel Composer und
  Datenbank-Tooling) werden strikt über Argument-Arrays ausgeführt. Guardian
  baut oder führt niemals einen Shell-Befehls-String aus und verwendet
  niemals `exec`, `shell_exec`, `system` oder Backticks. Das eliminiert
  Command-Injection als Risikoklasse.
- **Abgekoppelte Worker.** Lang laufende, hochwirksame Operationen laufen in
  dedizierten Hintergrundprozessen statt im Web-Request, sodass ein
  Browser-Verbindungsabbruch eine Operation nicht in einem mehrdeutigen
  Zwischenzustand belassen kann.
- **Geheimnisse nicht auf der Kommandozeile.** Sensible Werte, die ein
  Subprozess benötigt, werden über die Prozessumgebung übergeben, niemals als
  Kommandozeilenargumente, damit sie nicht in Prozesslisten oder
  Diagnoseausgaben erscheinen.
- **Validierung der Binärdatei.** Die von Guardian verwendete PHP-CLI-Binärdatei
  wird als echter Kommandozeileninterpreter validiert, bevor ihr die
  Ausführung eines Jobs anvertraut wird.

## 6. Dateisystem- und Archivsicherheit

- **Eingrenzung.** Guardian begrenzt seinen gesamten Laufzeitzustand auf ein
  einziges privates Arbeitsverzeichnis unter dem `var/`-Verzeichnis des
  Projekts. Jeder abgeleitete Pfad wird normalisiert und geprüft, sodass ein
  Pfad, der diese Grenze verlassen würde, zurückgewiesen wird.
- **Verhinderung von Traversal und unsicheren Links.** Die Pfadbehandlung löst
  relative Segmente auf, ohne für die Sicherheitsentscheidung Symlinks zu
  folgen, sodass ein symbolischer Link einen „eingegrenzten“ Pfad nicht aus
  seiner Grenze heraustunneln kann. Archivinhalte werden vor der Extraktion
  geprüft, und jeder absolute Pfad oder jedes Eltern-Verzeichnis-Segment wird
  zurückgewiesen.
- **ZIP-Upload-Prüfung.** Hochgeladene Archive werden vor der Verwendung auf
  Path-Traversal, unsichere symbolische Links, übermäßige Eintragsanzahlen
  oder -größen sowie Merkmale einer Dekompressionsbombe geprüft; ein Archiv,
  das eine Prüfung nicht besteht, wird zurückgewiesen und niemals in das
  Projekt extrahiert.
- **Privates Staging.** Hochgeladener und in Bearbeitung befindlicher Inhalt
  wird in einem privaten Staging-Bereich mit restriktiven Berechtigungen
  behandelt und erst nach bestandener Validierung in das Projekt übernommen.

## 7. Sicherheit von Update und Extension-Verwaltung

- **Vorschau vor Änderung.** Updates und Extension-Installationen/-Entfernungen
  werden zunächst in einem isolierten Probelauf analysiert; das Live-Projekt
  wird während der Analyse nicht verändert.
- **Abgesicherte Ausführung.** Eine Live-Operation erfordert eine explizite
  Administratorbestätigung und wird von einem verpflichtenden
  Sicherheits-Backup eingeleitet.
- **Verwaltete Eigentümerschaft.** Lokal installierte Extensions werden mit
  Metadaten zur verwalteten Eigentümerschaft nachverfolgt, sodass Guardian nur
  Quellverzeichnisse entfernt, die es selbst erstellt hat, und einen zuvor
  entfernten Upload sicher erneut installieren kann. Guardian löscht niemals
  implizit fremde Dateien oder sein eigenes Paketverzeichnis.
- **Schutz der Selbstverwaltung.** Das Deaktivieren oder Entfernen von Guardian
  selbst erfordert eine eingetippte Bestätigung und läuft über einen
  kontrollierten, verzögerten Pfad.

## 8. Sicherheit von Backup und Recovery

- **Backup-Integrität.** Backups tragen ein Manifest und Prüfsummen und werden
  validiert, bevor sie zur Wiederherstellung angeboten werden.
  Aufbewahrungsgrenzen werden durchgesetzt.
- **Backup-Speicherort.** Backups werden innerhalb des privaten
  Arbeitsverzeichnisses von Guardian gespeichert und nicht an
  web-zugänglichen oder beim Deployment gelöschten Orten abgelegt.
- **Verpflichtende Backups vor Änderungen.** Update- und
  Extension-Operationen erstellen vor jeder Änderung ein Sicherheits-Backup,
  damit der vorherige Zustand wiederhergestellt werden kann.
- **Recovery ist destruktiv und abgesichert.** Recovery erfordert einen
  verpflichtenden Probelauf, eine explizite Administratorbestätigung und eine
  Komponentenauswahl. Es stellt Dateien und Datenbank aus einem validierten
  Backup wieder her, baut Abhängigkeiten sicher isoliert neu auf und
  wechselt sie atomar ein.
- **Transaktionale Wiederherstellung.** Recovery wird journalisiert, sodass
  eine unterbrochene Wiederherstellung erkannt und zurückgerollt werden kann,
  und das Ergebnis wird anschließend verifiziert.

## 9. Schutz der eigenständigen Recovery

Guardian kann einen in sich geschlossenen Wiederherstellungs-Einstiegspunkt in
das öffentliche Web-Root ausliefern, damit eine Site auch dann wiederhergestellt
werden kann, wenn TYPO3 nicht mehr startet. Weil diese Komponente einzigartig
sensibel ist, ist sie geschützt durch:

- Opt-in-Deployment, ausgelöst durch den Administrator, mit unkomplizierter
  Entfernung;
- einen konfigurierbaren, schwer zu erratenden Dateinamen;
- Token-basierte Authentifizierung mit zeitkonstantem Vergleich, wobei das
  Token nur in gehashter Form gespeichert oder über eine
  Server-Umgebungsvariable bereitgestellt wird;
- Brute-Force-Ratenbegrenzung;
- Wiederverwendung derselben validierten Recovery-Engine wie das Backend,
  statt einer separaten, weniger geprüften Implementierung.

Administratoren wird dringend empfohlen, dem Recovery-Einstiegspunkt
Zugriffsbeschränkungen auf Webserver-Ebene hinzuzufügen (zum Beispiel eine
IP-Positivliste oder HTTP-Authentifizierung) und ihn zu entfernen, wenn er
nicht benötigt wird.

## 10. Geheimnisse und Logging

- **Schwärzung von Geheimnissen.** Log-Ausgaben und API-Antworten durchlaufen
  eine zentrale Schwärzung, sodass Zugangsdaten, Tokens, Transport-Connection-
  Strings und ähnliche Werte entfernt werden, bevor irgendetwas den Server
  verlässt.
- **Keine sensiblen Werte im Browser.** Der vollständige Lizenzschlüssel,
  Lizenz-Authentifizierungsmaterial und Recovery-Tokens werden niemals an den
  Client ausgeliefert. Das Logging ist so geschwärzt, dass dieses Material
  auch gewöhnliche Logs nicht erreicht.
- **Sicherheitsrelevantes Logging.** Hochwirksame Operationen und Fehlschläge
  werden in Guardians Job-Logs und im TYPO3-System-Log erfasst, in einer
  Form, die Auditing ermöglicht, ohne Geheimnisse oder absolute
  Installationspfade offenzulegen.

## 11. Externe Kommunikation

- **Feste, vertrauenswürdige Dienste.** Guardian kommuniziert mit einer
  kleinen, festen Menge vertrauenswürdiger V-T.ONE-HTTPS-Dienste für
  Lizenzaktivierung, -aktualisierung und ein operatives Nutzungssignal, plus
  den öffentlichen Extension-Repository-Abfragen, die vom Extensions-Bereich
  genutzt werden. Die Ziele sind im Produkt fest hinterlegt und können weder
  durch Konfiguration noch durch Request-Daten noch durch eine
  Remote-Antwort umgeleitet werden.
- **TLS erzwungen.** Die Transportsicherheitsprüfung ist für diese Requests
  immer aktiviert; Zertifikats- oder Host-Verifikation wird niemals
  deaktiviert.
- **Minimale Daten.** Das operative Signal überträgt nur den
  Produktbezeichner und die normalisierte Site-Domain. Kein Lizenzschlüssel
  und keine personenbezogenen Daten sind enthalten.
- **Ausfallsicher.** Ist ein externer Dienst nicht erreichbar, arbeitet
  Guardian innerhalb der Grenzen der lokal validierten Lizenz weiter; ein
  Netzwerkausfall gewährt niemals Zugriff, der sonst nicht bestünde.

## 12. Fehlerverhalten und Rollback

- **Automatisches Rollback.** Schlägt ein Live-Update oder eine
  Extension-Operation nach Beginn der Änderungen auf irgendeiner Stufe fehl,
  stellt Guardian den Zustand vor der Operation aus dem verpflichtenden
  Sicherheits-Backup wieder her.
- **Bereinigung des Wartungsmodus.** Der Wartungsmodus wird nach einer
  Operation immer in seinen vorherigen Zustand zurückversetzt, auch nach
  einem Fehlschlag, sodass ein abgestürzter Lauf eine Site nicht dauerhaft im
  Wartungsmodus belassen kann.
- **Operationssperren.** Eine benannte, nicht blockierende Sperre mit
  Wiederaufnahme veralteter Sperren verhindert gleichzeitige hochwirksame
  Operationen und stellt sicher, dass ein abgestürzter Lauf das System nicht
  blockieren kann.
- **Unabhängigkeit von Benachrichtigungen.** Ein Fehlschlag beim Versenden
  einer Benachrichtigung (zum Beispiel einer Recovery-E-Mail) ändert niemals
  die Gültigkeit der Lizenz oder das Ergebnis einer Operation.

## 13. Betriebliche Verantwortlichkeiten

Guardian setzt starke technische Kontrollen durch, aber eine sichere
Bereitstellung hängt auch vom Administrator ab:

- TYPO3-Administratorkonten auf vertrauenswürdiges Personal beschränken;
- das `var/`-Verzeichnis des Projekts nur für die Anwendung beschreibbar
  halten und vor öffentlichem Zugriff schützen;
- heruntergeladene Backups und exportierte Recovery-Zugangsdaten sicher und
  außerhalb des öffentlichen Web-Roots aufbewahren;
- den eigenständigen Recovery-Einstiegspunkt schützen und, wenn ungenutzt,
  entfernen, sowie ihm Zugriffsbeschränkungen auf Webserver-Ebene
  hinzufügen;
- Host, PHP und TYPO3-Core gepatcht halten;
- Recovery-Tokens und Lizenz-Zugangsdaten als sensibel behandeln und bei
  Verdacht auf Offenlegung rotieren.

## 14. Sicherheitsgrenzen

- Guardian arbeitet mit den Rechten des PHP-Prozesses; es kann nicht vor
  einem kompromittierten Host, einem kompromittierten
  TYPO3-Administratorkonto oder Dateisystemzugriff außerhalb von Guardian,
  der auf anderem Weg erlangt wurde, schützen.
- Der korrekte Ablauf von Updates, Recovery und Backups hängt von der
  Verfügbarkeit und Korrektheit der PHP-CLI, von Composer, der Datenbank und
  dem Archivierungs-Tooling des Hosts ab.
- Ein Rollback stellt aus dem jüngsten Sicherheits-Backup wieder her; es kann
  keine Daten wiederherstellen, die nach diesem Backup entstanden sind.
- Der eigenständige Recovery-Einstiegspunkt ist absichtlich mächtig; seine
  Sicherheit hängt davon ab, Dateiname und Token vertraulich zu halten und
  die empfohlenen Webserver-Schutzmaßnahmen anzuwenden.
- Die serverseitige Berechtigungsdurchsetzung schützt Guardians Funktionen;
  sie verhindert nicht und kann nicht verhindern, dass ein Administrator den
  eigenen selbst gehosteten Quellcode verändert — das bleibt gemäß den
  Lizenzbedingungen die Verantwortung des Administrators.
