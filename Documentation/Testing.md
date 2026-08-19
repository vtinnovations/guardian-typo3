# Tests

Guardians Phase-1-Tests sind **CMS-unabhängige Unit-Tests**: Sie prüfen reine
`Domain/`-Logik und erweitern direkt `PHPUnit\Framework\TestCase`, sodass sie
**kein** TYPO3 bootstrapen und unabhängig von TYPO3 13.4 oder 14 identisch
laufen.

> ⚠️ Diese Tests wurden in der Umgebung, in der das Grundgerüst erstellt
> wurde, **nicht** ausgeführt (dort steht keine PHP-/Composer-/
> TYPO3-Laufzeitumgebung zur Verfügung). Die untenstehenden Befehle sind das,
> was in einer geeigneten Umgebung auszuführen ist. Betrachten Sie sie nicht
> als bestanden.

## Was abgedeckt ist (Phase 1)

Reine, deterministische Logik — die wertvollste, framework-freie Oberfläche:

| Bereich | Test |
|---|---|
| Validierung der Laufzeitkonfiguration | `Tests/Unit/Domain/Configuration/RuntimeConfigurationTest.php` |
| Sichere Pfadnormalisierung & Eingrenzung | `Tests/Unit/Domain/Filesystem/PathNormalizerTest.php` |
| Zeitplan-Mathematik für fällig/nächster Lauf | `Tests/Unit/Domain/Schedule/ScheduleEvaluatorTest.php` |
| Archiv-Traversal (Zip-/Tar-Slip) | `Tests/Unit/Domain/Archive/ArchiveEntryValidatorTest.php` |
| Shell-freie Befehlskonstruktion | `Tests/Unit/Domain/Process/CommandRequestTest.php` |
| Job-Zustandsübergänge | `Tests/Unit/Domain/Job/JobTest.php` |
| Lock-Verhalten (echte Temp-Datei) | `Tests/Unit/Infrastructure/Lock/FlockLockTest.php` |

Da diese nicht von TYPO3 abhängen, validiert ein einziger Lauf die Logik für
beide unterstützten TYPO3-Linien.

Die Suite deckt außerdem Berechtigungs- und Lizenzbehandlung,
Backend-Verdrahtung, Log-Schwärzung und das Release-Artefakt ab. Diese Tests
werden hier nicht einzeln aufgeführt: Die obige Tabelle ist eine
Orientierungshilfe für Mitwirkende, die an den operativen Teilen des Produkts
arbeiten, und eine Auflistung der Berechtigungstests würde interne Details
beschreiben, die diese Dokumentation absichtlich auslässt.
`vendor/bin/phpunit` führt alles aus.

## Abhängigkeitsbereiche

`composer.json` (`require-dev`) umspannt absichtlich beide TYPO3-Ären, damit
Composer die zum installierten Core passende Toolchain auflösen kann:

```json
"require-dev": {
    "phpunit/phpunit": "^10.5 || ^11.0",
    "typo3/testing-framework": "^8.2.3 || ^9.0"
}
```

- `typo3/testing-framework ^8.2.3` deckt die TYPO3-13.4-Linie ab; `^9.0`
  deckt die TYPO3-14-Linie ab. Composer wählt die zum installierten Core
  passende Version.
- PHPUnit `^10.5 || ^11.0` ist mit beiden testing-framework-Hauptversionen
  kompatibel sowie mit den in den Tests verwendeten Attributen `#[Test]` /
  `#[DataProvider]`.
- `phpunit.xml.dist` verwendet nur Attribute, die sowohl in PHPUnit 10.5 als
  auch in 11 gültig sind.

## Befehle (in einer echten PHP-/Composer-Umgebung ausführen)

```bash
# Abhängigkeiten installieren
composer install

# Die CMS-unabhängigen Unit-Tests ausführen
vendor/bin/phpunit -c phpunit.xml.dist

# Verifizieren, dass sich die Mindest-Constraints tatsächlich auflösen (TYPO3-13.4.9-/PHP-8.2-Basis)
composer update --prefer-lowest --prefer-stable
vendor/bin/phpunit -c phpunit.xml.dist
```

## CI-Matrix (in isolierten Installationen auszuführen)

Jede Zelle ist eine frische, auf die angegebene TYPO3-Linie festgelegte
Composer-Installation, gefolgt von der Unit-Suite (und, in späteren Phasen,
TYPO3-Funktionstests):

| # | TYPO3 | PHP | Composer-Flags | Zweck |
|---|---|---|---|---|
| 1 | 13.4.9 | 8.2 | `--prefer-lowest --prefer-stable` | absolute Mindestbasis |
| 2 | neueste 13.4.x | 8.3 (oder 8.4) | Standard | aktuelle 13.4-LTS |
| 3 | niedrigste 14.0 | PHP-Untergrenze des Cores | Standard | Einstiegspunkt für 14 |
| 4 | neueste 14.x | neueste unterstützte PHP-Version | Standard | aktuelle 14-Linie |

Beispiel für das Festlegen einer Zelle vor der Installation:

```bash
composer require --dev --no-update "typo3/cms-core:^13.4.9" "typo3/cms-backend:^13.4.9"
composer update --prefer-lowest --prefer-stable
```

## Funktionstests (spätere Phasen)

Wenn destruktive Funktionen hinzukommen, werden TYPO3-Funktionstests unter
`Tests/Functional/` mit `typo3/testing-framework` ergänzt, mit einer separaten
`Build/FunctionalTests.xml`. Sie laufen in derselben CI-Matrix. In Phase 1
existieren keine, da es kein Laufzeitverhalten gibt (keine Schreibvorgänge,
keine Prozessausführung), das gegen ein gebootetes TYPO3 geprüft werden
müsste.

## Noch ausstehende Laufzeitprüfungen

- Backend-Modul-Smoke-Test auf einer echten **TYPO3-13.4.9**- und einer
  echten **14.x**-Installation (beide Module für Administratoren unter
  *System* sichtbar; alle Abschnitte rendern; DI kompiliert;
  Icon/Labels/XLF werden aufgelöst).
- Lesen der **Site-Konfiguration** gegen eine echte Installation: Das
  Inventar wird aus `SiteFinder` aufgebaut (Site-`base`, Sprach-`base`,
  `baseVariants`), was die Unit-Suite über ein Double statt über TYPO3 prüft.
- **Backend-Session-Claim** gegen eine echte `be_sessions`-Zeile: Das
  Einmal-pro-Session-Verhalten wird hier über ein Double geprüft, sodass das
  Lese-/Schreibpaar der TYPO3-Session und die Sperre darum noch eine echte
  Prüfung mit zwei parallelen Tabs benötigen.
- **Live-Interoperabilität mit V-T.ONE**:
  `Tests/Unit/Support/ProductionVectors.php` ist noch leer, sodass noch kein
  von V-T.ONE erzeugter Vektor durch den Verifikationspfad dieses Clients
  abgespielt wurde.
