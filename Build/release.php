<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

/**
 * Builds the distributable artefact.
 *
 * Shipping the working copy is not a release. This script produces a separate
 * tree and, in doing so, enforces the things that are easy to forget by hand:
 *
 *   1. the build refuses to run at all unless the pinned verification keys are
 *      present and sound, because an artefact that cannot verify anything the
 *      vendor sends looks broken for reasons nobody can diagnose from outside;
 *   2. tests, fixtures, build tooling and development documentation are left
 *      behind, so the shipped tree contains only what runs;
 *   3. comments and formatting are removed from the shipped PHP, which keeps the
 *      explanatory notes in this repository rather than in every customer's
 *      vendor directory;
 *   4. a SHA-256 manifest of the security-relevant files is written, so an
 *      installation can be checked against what was actually published;
 *   5. the result is verified — every file parses, the manifest agrees with the
 *      tree, and nothing that should have been stripped survived.
 *
 * What this deliberately does NOT do is rename private symbols. A TYPO3
 * extension is wired by fully-qualified class name: the service container, the
 * middleware stack, the console command registry and PSR-4 autoloading all
 * resolve classes by name, and renaming them would have to keep every one of
 * those in step. That trade is not worth taking, so the structural hardening
 * here is the distribution of responsibilities in the source tree itself, and
 * the limitation is stated plainly rather than papered over.
 *
 * Usage:  php Build/release.php [target-directory]
 */

$root = \dirname(__DIR__);
$target = $argv[1] ?? $root . '/.build/guardian_typo3';

require $root . '/vendor/autoload.php';

// The readiness gate must inspect the tree being built, not whichever copy of
// the extension the shared autoloader happens to resolve first.
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Vtinnovations\\GuardianTypo3\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $root . '/Classes/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
}, true, true);

use Vtinnovations\GuardianTypo3\Infrastructure\Version\ReleaseKeyring;

/** Files and directories that are part of development, not of the product. */
const EXCLUDED = [
    'Tests',
    'Build',
    // Internal correspondence and provisioning notes. Never customer-facing.
    '.internal',
    '.build',
    'vendor',
    'node_modules',
    '.git',
    '.github',
    '.claude',
    '.phpunit.cache',
    'phpunit.xml.dist',
    'composer.lock',
    '.DS_Store',
    'deploy-brickie.sh',
];

/** Development documentation that describes the build rather than the product. */
const EXCLUDED_DOCS = [
    'Documentation/ContaoSourceAudit.md',
    'Documentation/ContaoUiParityMatrix.md',
    'Documentation/ImplementationRoadmap.md',
    'Documentation/VisualParityChecklist.md',
    'Documentation/CompatibilityAudit.md',
    'Documentation/CompatibilityAudit.en.md',
];

/** Files whose integrity a customer may want to confirm. */
const MANIFESTED = ['Classes', 'Configuration'];

function fail(string $message): never
{
    fwrite(\STDERR, "\n  BUILD FAILED: " . $message . "\n\n");
    exit(1);
}

function step(string $message): void
{
    echo '  · ' . $message . "\n";
}

// ── 1. Readiness gate ───────────────────────────────────────────────────────

echo "\nBuilding the Guardian for TYPO3 release artefact.\n\n";

$problems = (new ReleaseKeyring())->productionReadiness();
if ($problems !== []) {
    fail(
        "this build cannot verify anything the vendor sends:\n"
        . '    - ' . implode("\n    - ", $problems) . "\n\n"
        . "  Pin the approved vendor verification key in\n"
        . '  Classes/Infrastructure/Version/ReleaseKeyring.php, then build again.'
    );
}
step('verification keys: ' . implode(', ', (new ReleaseKeyring())->keyIds()));

// ── 2. Copy the product, leaving development behind ─────────────────────────

if (is_dir($target)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($target);
}
if (!@mkdir($target, 0o755, true) && !is_dir($target)) {
    fail('the target directory could not be created: ' . $target);
}

$copied = 0;
$stripped = 0;
$excludedDocs = array_flip(EXCLUDED_DOCS);

$walker = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $entry) use ($root): bool {
            $relative = str_replace($root . '/', '', $entry->getPathname());

            return !\in_array(explode('/', $relative)[0], EXCLUDED, true)
                && $entry->getBasename() !== '.DS_Store';
        }
    )
);

foreach ($walker as $entry) {
    if (!$entry instanceof SplFileInfo || !$entry->isFile()) {
        continue;
    }
    $relative = str_replace($root . '/', '', $entry->getPathname());
    if (isset($excludedDocs[$relative])) {
        continue;
    }

    $destination = $target . '/' . $relative;
    $directory = \dirname($destination);
    if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
        fail('could not create ' . $directory);
    }

    // 3. Shipped PHP loses its comments and formatting. Attributes, which the
    //    framework reads, are tokens rather than comments and survive intact.
    if ($entry->getExtension() === 'php' && str_starts_with($relative, 'Classes/')) {
        $contents = php_strip_whitespace($entry->getPathname());
        if ($contents === '') {
            fail('stripping produced an empty file: ' . $relative);
        }
        file_put_contents($destination, $contents);
        $stripped++;
    } else {
        copy($entry->getPathname(), $destination);
    }
    $copied++;
}

step($copied . ' files copied, ' . $stripped . ' stripped of comments');

// ── 4. Manifest ─────────────────────────────────────────────────────────────

$manifest = [];
foreach (MANIFESTED as $subtree) {
    $path = $target . '/' . $subtree;
    if (!is_dir($path)) {
        continue;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $relative = str_replace($target . '/', '', $file->getPathname());
            $manifest[$relative] = hash_file('sha256', $file->getPathname());
        }
    }
}
ksort($manifest);

$manifestPath = $target . '/Resources/Private/release-manifest.json';
if (!is_dir(\dirname($manifestPath))) {
    @mkdir(\dirname($manifestPath), 0o755, true);
}
file_put_contents(
    $manifestPath,
    json_encode(['algorithm' => 'sha256', 'files' => $manifest], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n"
);
step(\count($manifest) . ' files listed in the release manifest');

// ── 5. Verify the artefact, not the source ──────────────────────────────────

foreach (array_keys($manifest) as $relative) {
    if (!str_ends_with($relative, '.php')) {
        continue;
    }
    $output = [];
    $status = 0;
    exec('php -l ' . escapeshellarg($target . '/' . $relative) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        fail('the built file does not parse: ' . $relative . "\n    " . implode("\n    ", $output));
    }
}
step('every built PHP file parses');

foreach ($manifest as $relative => $digest) {
    if (hash_file('sha256', $target . '/' . $relative) !== $digest) {
        fail('the manifest disagrees with the tree: ' . $relative);
    }
}
step('the manifest agrees with the tree');

foreach (['Tests', 'Build', '.build', '.internal', 'phpunit.xml.dist', 'vendor'] as $shouldBeAbsent) {
    if (file_exists($target . '/' . $shouldBeAbsent)) {
        fail('development content survived into the artefact: ' . $shouldBeAbsent);
    }
}
step('no development content in the artefact');

// Nothing that can sign may ever be shipped; only verification belongs here.
$signing = [];
$builtFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target . '/Classes', FilesystemIterator::SKIP_DOTS));
foreach ($builtFiles as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    foreach (['sodium_crypto_sign_detached', 'sodium_crypto_sign_secretkey', 'PRIVATE KEY'] as $marker) {
        if (str_contains($source, $marker)) {
            $signing[] = str_replace($target . '/', '', $file->getPathname()) . ': ' . $marker;
        }
    }
}
if ($signing !== []) {
    fail("the artefact contains signing capability:\n    - " . implode("\n    - ", $signing));
}
step('the artefact can verify but not sign');

// The licence screen's controls are the only way an administrator can activate,
// refresh or withdraw a licence, and a script that binds nothing takes all three
// away without saying so. TYPO3 loads that script from the head, before the
// module markup exists, so the artefact's own copy is executed here in both
// orders and every control is clicked. This checks the built file rather than the
// source, because the source is not what customers run.
$gate = $root . '/Tests/Browser/vtone-packages.lifecycle.mjs';
$builtAsset = $target . '/Resources/Public/JavaScript/vtone-packages.js';
if (!is_file($builtAsset)) {
    fail('the licence screen script is missing from the artefact');
}

$node = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($node === '' || !is_file($gate)) {
    // Not installing one: an unavailable check is reported, never worked around.
    step('licence-control acceptance NOT RUN (node is unavailable) — run it before publishing');
} else {
    $output = [];
    $status = 0;
    exec(
        'VTONE_ASSET=' . escapeshellarg($builtAsset) . ' ' . escapeshellarg($node) . ' ' . escapeshellarg($gate) . ' 2>&1',
        $output,
        $status
    );
    if ($status !== 0) {
        fail(
            "the artefact's licence controls are not operational:\n    "
            . implode("\n    ", array_slice($output, -25))
        );
    }
    step('every licence control in the artefact binds and fires in both load orders');
}

echo "\n  Artefact ready: " . $target . "\n\n";
