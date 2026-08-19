# Kompatibilitätsstrategie — TYPO3 13.4 / 14

Wie Guardian **TYPO3 13.4.9 bis 14.x** aus einem einzigen Paket unterstützt,
und die Regeln, die das sicherstellen.

## Unterstützte Versionen

| Achse | Unterstützung |
|---|---|
| TYPO3 | **13.4.9** (Minimum) bis **14.x** |
| PHP | Minimum **8.2**, bis 8.5 (`~8.2.0 \|\| ~8.3.0 \|\| ~8.4.0 \|\| ~8.5.0`) |
| Installationsmodus | Composer (primärer und einzig offiziell unterstützter Modus) |

Die effektive PHP-Untergrenze auf einer gegebenen Site ist das, was der
installierte TYPO3-Core erzwingt (TYPO3 13.4 läuft ab PHP 8.2+; TYPO3 14 hebt
die Untergrenze an). Guardian selbst erzwingt nur das Minimum von 8.2, damit
es niemals eine gültige TYPO3-13.4-Installation blockiert.

## Composer-Constraints

Konsistent auf jedes direkt benötigte `typo3/cms-*`-Paket angewendet:

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

- **Kein `typo3/cms`-Metapaket** — nur die beiden tatsächlich genutzten
  Unterpakete.
- `psr/http-message: ^1.1 || ^2.0` entspricht der Spanne, die TYPO3 13.4/14
  selbst erlauben; Composer löst dies auf das auf, was der installierte Core
  vorgibt.
- Die Dev-Tooling-Constraints umspannen beide Ären:
  `phpunit/phpunit: ^10.5 || ^11.0`, `typo3/testing-framework: ^8.2.3 || ^9.0`
  — Composer wählt die zum installierten TYPO3 passende Kombination.

## Warum keine `ext_emconf.php`

Der einzige unterstützte Installationsmodus ist Composer. Im Composer-Modus
leitet TYPO3 alle Extension-Metadaten (Extension-Key, Titel,
Versions-Constraints, Abhängigkeiten) aus `composer.json` ab (`type:
typo3-cms-extension`, `extra.typo3/cms.extension-key` sowie die
`typo3/cms-*`-Versions-Constraints). Eine `ext_emconf.php` wäre nur für
**klassische (Nicht-Composer-)Installationen** nötig, die Guardian nicht
adressiert, und müsste dann die bereits in `composer.json` vorhandenen
Constraints duplizieren — eine garantierte Quelle für Abweichungen und
widersprüchliche Deklarationen.

Daher wird `ext_emconf.php` absichtlich **weggelassen**. Sollte jemals
Unterstützung für den klassischen Modus hinzukommen, muss die Datei exakt
Folgendes deklarieren: TYPO3 `13.4.9`–unterhalb-`15`
(`'typo3' => '13.4.9-14.4.99'`), PHP-Minimum `8.2.0`, Extension-Key
`guardian_typo3`, Status `beta`/`stable` je nach Reifegrad — passend zu
`composer.json`, ohne Widerspruch.

## Richtlinie zur gemeinsamen Teilmenge

Der Standard ist **eine gemeinsame Implementierung**. TYPO3-spezifischer Code
ist auf die `Classes/Typo3/`-Adapter, den einzelnen Backend-Controller sowie
`Configuration/`+`Resources/` begrenzt. Innerhalb dieser Oberfläche werden nur
APIs verwendet, die in 13.4.9 und 14 gleichermaßen vorhanden und identisch
sind:

- Array-basierte Backend-Modul-Registrierung (`routes/_default/target`).
- `ModuleTemplateFactory` / `ModuleTemplate` (inkl. `renderResponse()`, ab
  v12+).
- `UriBuilder::buildUriFromRoute()`.
- Pfad-/Modus-Zugriffe von `Environment`.
- Backend-User über `BackendUserAuthentication` (im Adapter isoliert).
- `Configuration/Icons.php` + `SvgIconProvider`.
- Symfony DI (`Configuration/Services.yaml`) ohne Nur-v14-Schlüssel.
- Ausschließlich Core-Fluid-ViewHelper.
- Reines Browser-JavaScript (kein `@typo3/backend/*`-Import).

**Nirgends wird ein Laufzeit-Versionsvergleich (`version_compare`,
`VersionNumberUtility`) verwendet** — wo eine gemeinsame API existiert, wird
sie direkt genutzt.

## Für Versionsunterschiede eingeführte Adapter

**Keine.** Es wurde keine TYPO3-13.4-↔-14-API-Inkompatibilität gefunden (siehe
`CompatibilityAudit.md`). Die `Classes/Typo3/`-Adapter existieren, um das CMS
von der reinen Domain zu isolieren — eine Design-Grenze, kein Versions-Shim.

Sollte in einer späteren Phase eine echte Inkompatibilität auftreten, gilt
folgende Regel:

1. Eine anwendungsseitige Schnittstelle (einen Port) in
   `Application/Contract/` definieren.
2. Das Verhalten jeder Version in einen separaten
   Infrastructure-/TYPO3-Adapter legen.
3. Den Adapter über DI / eine Fähigkeitsprüfung im Adapter auswählen —
   **nicht** über ein durch Controller oder Domain-Code verstreutes
   `version_compare`.
4. Controller und Domain-Services versionsneutral halten.
5. Den Adapter hier und in `CompatibilityAudit.md` dokumentieren.

## Bewusst vermiedene APIs

- Das `typo3/cms`-Metapaket (zieht unnötige Pakete nach).
- Jede Backend-Modul-Verdrahtung, die erst ab v14 existiert.
- Veraltete RequireJS-/`define()`-globale JS-Module.
- Verzweigung nach Laufzeit-Hauptversion.

## CI-Matrix (in isolierten Installationen auszuführen)

| TYPO3 | PHP | Zweck |
|---|---|---|
| 13.4.9 (niedrigste) | 8.2 | absolute Mindestbasis |
| neueste 13.4.x | 8.3 (oder 8.4) | aktuelle 13.4-LTS-Linie |
| niedrigste unterstützte 14.0 | 8.2 → löst auf die Untergrenze des Cores auf | Einstiegspunkt für 14 |
| neueste 14.x | neueste unterstützte PHP-Version | aktuelle 14-Linie |

`--prefer-lowest`-Läufe auf der Zelle 13.4.9/PHP-8.2 verifizieren, dass sich
die Mindest-Constraints tatsächlich auflösen lassen. Siehe `Testing.md` für
die genauen Befehle.

## Release- und Deprecation-Richtlinie

- Ein Paket, ein Branch, unterstützt 13.4.9→14.x für die Lebensdauer der
  TYPO3-13.4-LTS.
- Fehlerbehebungen zielen auf die gemeinsame Codebasis; keine
  versionsspezifischen Forks.
- Deprecations werden vermieden, indem auf der gemeinsamen Teilmenge geblieben
  wird; sollte ein späteres TYPO3 15 etwas entfernen, wird die Unterstützung
  über einen Adapter hinzugefügt (gemäß obiger Regel), niemals über eine
  Breaking Change am gemeinsamen Code.

## Wegfall von TYPO3 13 (zukünftig)

Wenn die TYPO3-13.4-LTS das Ende ihres Lebenszyklus erreicht:

1. `typo3/cms-*` in einem neuen **Major**-Release von Guardian auf
   `^14.4 || ^15.0` (oder passend) anheben; die 13.4-kompatible Linie auf
   einem Wartungs-Branch belassen.
2. Die PHP-Untergrenze auf das Minimum von TYPO3 14 anheben.
3. Etwaige Nur-13.4-Adapter entfernen (aktuell keine vorhanden) und
   vereinfachen.
4. `CompatibilityAudit.md`, diese Datei, `Testing.md` und `README.md`
   aktualisieren.

## Noch erforderliche Laufzeitprüfungen

Statische Analyse kann die Installation auf echten Cores nicht ersetzen. Vor
einem Release die obige CI-Matrix ausführen und das Backend-Modul manuell auf
**beiden**, einer 13.4.9- und einer 14.x-Instanz, verifizieren (Modul
erscheint für Administratoren unter System; alle sieben Abschnitte rendern;
DI-Container kompiliert; Icon/Labels/XLF werden aufgelöst).
