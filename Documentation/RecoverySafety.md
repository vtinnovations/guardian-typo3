# Recovery-Sicherheit (Härtung nach dem Vorfall)

## Was eine Live-Site beschädigt hat

Die vorherige Wiederherstellung stellte Verzeichniskomponenten — einschließlich
`vendor/` — wieder her, indem sie **das Live-Verzeichnis an Ort und Stelle
leerte und das Archiv darüber extrahierte**
(`RestoreService::restoreEntries()` → `wipeDirectory()`, dann
`extractEntries()` in den Live-Projektpfad). Für `vendor/` ist das
katastrophal:

- der Live-Vendor wird gelöscht, **bevor** irgendein Ersatz bereit ist
  (nicht-atomar);
- ist der archivierte Vendor unvollständig, stammt er aus einer anderen
  Umgebung, wurde er unter macOS erzeugt, enthält er absolute/kaputte
  Symlinks, oder wurde das Archiv abgeschnitten, bleibt die Site mit einem
  halb befüllten Vendor und ohne Rückweg zurück;
- der laufende PHP-Prozess kann die genau für den Abschluss der
  Wiederherstellung benötigten Klassen nicht mehr autoloaden.

Grundursache: **In-Place-, nicht gestufte, nicht-atomare Vendor-Überschreibung
ohne Neuaufbau, ohne Verifizierung und ohne erhaltenen vorherigen Vendor.**

## Das gehärtete Modell

Das direkte Überschreiben des Vendors wurde entfernt. `vendor/` wird nie mehr
an Ort und Stelle geleert — eine harte Sperre in
`RestoreService::wipeDirectory()` verweigert jeden Pfad namens `vendor`, und
Vendor ist überhaupt kein Teil der In-Place-Restore-Reihenfolge mehr.

### Vendor-Strategien (`VendorRestoreStrategy`)

- **Neuaufbau (Standard)** — `composer.json`/`composer.lock` wiederherstellen,
  dann `composer install` in einem isolierten Staging-Verzeichnis ausführen
  und dieses atomar einwechseln.
- **Überspringen** — Vendor nicht anfassen.
- **Archiviert (fortgeschritten, hohes Risiko)** — nur wenn strikte Prüfungen
  bestehen; wird dennoch gestuft, validiert und atomar eingewechselt.
  Erfordert die Eingabe von `RESTORE VENDOR`.

Das veraltete Komponenten-Flag `vendor: true` wird **serverseitig
zurückgewiesen**, sowohl im Backend als auch im eigenständigen Panel; Vendor
wird ausschließlich über die Strategie gesteuert.

### Gestufter Neuaufbau + atomarer Wechsel (`VendorRecoveryService`, `AtomicDirectorySwitch`)

1. Wiederhergestellte `composer.json` + `composer.lock` validieren (gültiges
   JSON).
2. PHP-CLI + Composer-Binärdatei validieren.
3. Ein isoliertes Verzeichnis, das sich am selben Ort wie der Live-Vendor
   befindet (garantiert dasselbe Dateisystem), aufbauen, dabei Projektdateien
   symlinken, damit Path-Repositories aufgelöst werden, und die
   wiederhergestellten Composer-Dateien hineinkopieren.
4. `composer install --no-interaction --no-progress --no-scripts
   --optimize-autoloader --working-dir=<staging>` — niemals gegen den
   Live-Vendor.
5. Den gestuften Vendor validieren: `autoload.php`,
   `composer/autoload_real.php`, `composer/installed.php`,
   `composer/installed.json` müssen existieren; `typo3/cms-core` muss
   vorhanden sein; die installierte Menge muss `composer.lock` entsprechen;
   und jeder Symlink muss innerhalb des Projekt-Roots bleiben.

   **Symlink-Regel.** Die Vertrauensgrenze ist der **Projekt-Root**, nicht der
   Vendor-Teilbaum, und die Eingrenzung wird **lexikalisch** (über
   `PathNormalizer`) am endgültigen Live-Ort jedes Links beurteilt — niemals
   mit `realpath()`, das sich durch die Staging-Build-Verzeichnis-Symlinks
   graben und die Eingrenzung falsch beurteilen würde. Das akzeptiert die
   beiden Symlink-Arten, die Composer legitim erzeugt — Bin-Proxys
   (`vendor/bin/typo3 -> ../typo3/cms-cli/typo3`, innerhalb von vendor) und
   lokale Path-Repository-Links (`vendor/acme/ext -> ../../packages/ext`,
   innerhalb des Projekts, aber außerhalb von vendor) —, während jeder
   Symlink zurückgewiesen wird, dessen normalisiertes Ziel den Projekt-Root
   als beliebiger externer Symlink verlässt. Zurückweisungen werden dem
   Administrator mit dem relativen Link-Pfad, dem rohen Ziel, dem
   normalisierten Ziel und dem Grund gemeldet.
6. Atomarer Wechsel: `rename(vendor → .guardian-old-vendor-<job>)`, dann
   `rename(<staging>/vendor → vendor)` — zwei Renames auf einem Dateisystem.
   Der Site fehlt zu keinem Zeitpunkt ein `vendor/`. `vendor/autoload.php`
   wird verifiziert; bei Fehlschlag wird der vorherige Vendor sofort
   wiederhergestellt.
7. Der vorherige Vendor wird **erhalten**, bis die gesamte Wiederherstellung
   erfolgreich ist; erst dann wird er verworfen. Ist ein atomares Rename
   unmöglich (unterschiedliche Dateisysteme), wird die Wiederherstellung
   **blockiert** — es gibt keinen rekursiven Überschreib-Fallback.

### Transaktionsjournal (`RecoveryTransactionJournal`)

Vor jedem destruktiven Schritt wird
`var/guardian/recovery/<job-id>/transaction.json` geschrieben und nach jedem
Schritt atomar aktualisiert (temp + rename); erfasst werden der Schritt,
verschobene/erstellte Pfade, alte/neue Vendor-Pfade, DB-Zustand, vorheriger
Wartungszustand, ID des Sicherheits-Snapshots sowie der Rollback-Zustand. Beim
nächsten Laden von Panel/Backend wird eine unvollständige Transaktion erkannt,
blockiert jede neue Wiederherstellung und bietet einen sicheren Rollback an
(`rollbackInterrupted()`).

### Verpflichtender Probelauf (`RecoveryDryRun`)

Eine echte Wiederherstellung wird verweigert, solange kein erfolgreicher
Probelauf für die **exakte** Kombination aus Backup + Komponenten +
Vendor-Strategie vorliegt (ein Fingerabdruck dieser Auswahl). Der Probelauf
validiert das Archiv, prüft Composer-Dateien, die Fähigkeit zum atomaren
Wechsel und den Speicherplatz (Archiv + Snapshot + gestufter Vendor +
erhaltener alter Vendor ≈ 4×) und nimmt keine Änderungen vor, aktiviert keine
Wartung und stellt keine Datenbank wieder her. Jede Änderung der Auswahl
macht den Fingerabdruck ungültig.

### Verpflichtender Sicherheits-Snapshot

Ein Snapshot vor der Wiederherstellung (Composer + DB + ausgewählte Dateien,
**ohne Vendor** — der Vendor-Rollback erfolgt über das atomare
Alt-Vendor-Rename, nicht über ein Archiv) wird immer erstellt; `restore()`
verweigert die Ausführung ohne ihn.

### Verifizierung nach der Wiederherstellung

Wurde Vendor angefasst, wird Erfolg erst gemeldet, nachdem:
- `vendor/autoload.php` in einem **separaten** PHP-CLI-Prozess lädt, und
- `vendor/bin/typo3 --version` bootstrapt (kompatibel mit TYPO3 13.4 + 14).

Ein Fehlschlag löst automatisches Rollback aus.

### Rollback

Bei jedem Fehlschlag, nachdem Änderungen begonnen haben: Der Vendor-Wechsel
wird rückgängig gemacht (alter Vendor atomar wiederhergestellt; der defekte
Baum wird zur Diagnose als `.guardian-failed-vendor-<job>` aufbewahrt),
Nicht-Vendor-Komponenten werden aus dem Sicherheits-Snapshot wiederhergestellt,
die Wartung bleibt EIN, bis der Rollback abgeschlossen ist, und das Ergebnis
wird als *zurückgerollt* / *Rollback unvollständig* gemeldet — niemals als
generischer Erfolg.

### Dieselbe Engine überall

Der Recovery-Tab im Backend und das eigenständige `_guardian-recovery.php`
rufen **dieselben** `RestoreService` / `VendorRecoveryService` /
`RecoveryDryRun` / `RecoveryTransactionJournal` über den
`StandaloneRecoveryKernel` auf. Es gibt keinen sichereren Backend-Pfad und
keinen unsicheren Panel-Pfad.

## Speicherplatzbedarf

Rechnen Sie mit etwa: Backup-Archiv + Sicherheits-Snapshot + gestufter Vendor +
erhaltener alter Vendor. Der Probelauf blockiert die Wiederherstellung, wenn
der freie Speicherplatz unter ~4× der Archivgröße liegt.

## Manueller Notfall-Rollback

Wird eine Wiederherstellung unterbrochen, öffnen Sie das Panel/Backend: Die
unterbrochene Transaktion wird mit einer **Zurückrollen**-Aktion angezeigt.
Manuell befindet sich der vorherige Vendor unter
`<project>/.guardian-old-vendor-<job-id>` — stellen Sie ihn als Projektbenutzer
mit `mv vendor .broken-vendor && mv .guardian-old-vendor-<job-id> vendor`
wieder her und leeren Sie anschließend die Caches.
