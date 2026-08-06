/*
 * Guardian for TYPO3 — backend behaviour.
 *
 * Faithful port of the interaction model from the original Contao Guardian
 * template's inline <script>: hash-based tab switching, license gating with the
 * upgrade dialog, the real-update modal, the schedule frequency rows, the
 * package table, pre-update analysis, and the read-only data loaders.
 *
 * TYPO3 adaptations:
 *   - No inline JavaScript: the original onclick="" handlers are replaced by a
 *     single delegated click listener keyed on [data-action] attributes.
 *   - Endpoint URLs and license/standalone state are read from a Fluid-generated
 *     JSON island (#guardian-config), never hard-coded. The URLs are already
 *     CSRF-tokenised backend AJAX routes built server-side with UriBuilder.
 *   - Destructive write actions (update, backup create/delete, restore, schedule
 *     save/run, runtime save, token rotate, emails) are
 *     rendered DISABLED in the markup in this build, so their handlers are absent
 *     here on purpose — nothing fakes success.
 *
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */
(function () {
    'use strict';

    var CFG = { pro: false, licensed: false, standaloneFilename: '_guardian-recovery.php', endpoints: {} };
    var packages = [];
    var updateState = { onlineCheckComplete: false, installedVersion: null, upgradePaths: [], selectedTargetVersion: null, selectedUpgradeType: null, dryRunStatus: 'not_run', canRunLive: false };

    var DEFAULT_TAB = 'dashboard';
    var VALID_TABS = ['dashboard', 'update', 'backup', 'recovery', 'extensions', 'settings'];
    var PRO_TABS = ['update', 'recovery', 'extensions'];
    // Manual backup is what the Free package unlocks, so its tab asks only for
    // a licence in effect rather than for Pro.
    var LICENSED_TABS = ['backup'];

    function t(key) {
        return window.TYPO3 && TYPO3.lang && TYPO3.lang[key] ? TYPO3.lang[key] : key;
    }

    function esc(str) {
        var div = document.createElement('div');
        div.textContent = String(str == null ? '' : str);
        return div.innerHTML;
    }

    function analysisText(code, fallback) {
        var translated = t('js.' + code);
        if (translated !== 'js.' + code) { return translated; }
        var english = {
            'analysis.composerMode':'Composer mode','analysis.composerMode.ok':'TYPO3 runs in Composer mode — updates are possible.','analysis.composerMode.error':'TYPO3 does not run in Composer mode.','analysis.composerFiles':'Composer files','analysis.composerFiles.ok':'Both Composer files are present.','analysis.composerFiles.error':'Composer files are missing.','analysis.phpVersion':'PHP version','analysis.phpVersion.ok':'meets the minimum requirement.','analysis.phpVersion.warning':'is older than recommended.','analysis.workingDirectory':'Working directory','analysis.workingDirectory.ok':'is writable.','analysis.workingDirectory.error':'is not writable.','analysis.vendorWritable':'vendor/ writable','analysis.vendorWritable.ok':'The vendor directory is writable.','analysis.vendorWritable.warning':'The vendor directory is not writable or missing.','analysis.phpCli':'PHP CLI binary','analysis.phpCli.ok':'A usable PHP CLI binary was found.','analysis.phpCli.warning':'No PHP CLI binary configured — set it in Settings.','analysis.composerBinary':'Composer binary','analysis.composerBinary.ok':'A composer.phar was found.','analysis.composerBinary.warning':'No composer.phar found — place one in the project root or configure its path.','analysis.consoleBinary':'TYPO3 console','analysis.consoleBinary.ok':'vendor/bin/typo3 is present.','analysis.consoleBinary.warning':'vendor/bin/typo3 was not found.','analysis.database':'Database','analysis.database.ok':'Database connection succeeded.','analysis.database.error':'Could not connect to the database.','analysis.diskSpace':'Disk space','analysis.diskSpace.ok':'Sufficient free disk space.','analysis.diskSpace.warning':'Low free disk space (under 512 MB).','analysis.diskSpace.unknown':'Could not determine free disk space.','analysis.license':'Pro license','analysis.license.ok':'A valid Pro license is active.','analysis.license.error':'Updates require a valid Pro license.','analysis.activeJob':'Running job','analysis.activeJob.ok':'No update job is currently running.','analysis.activeJob.warning':'An update job is already running.','analysis.backupCapability':'Backup capability','analysis.backupCapability.ok':'A safety backup can be created.','analysis.backupCapability.warning':'A safety backup may not be possible (working dir or zip extension).','analysis.summary.error':'Pre-update analysis found blocking issues.','analysis.summary.warning':'Pre-update analysis completed with warnings.','analysis.summary.ok':'Pre-update analysis passed — no issues found.'
        };
        return english[code] || fallback;
    }

    function root() { return document.getElementById('guardianModule'); }
    function byId(id) { return document.getElementById(id); }

    // The endpoint URLs + entitlement flags live in a Fluid JSON island. Parse it
    // lazily and exactly once, so every handler has the endpoints available
    // regardless of boot()/DOMContentLoaded/tab/render/asset timing.
    var configLoaded = false;
    function ensureConfig() {
        if (configLoaded) { return; }
        var cfgEl = document.getElementById('guardian-config');
        if (cfgEl) {
            try { CFG = Object.assign(CFG, JSON.parse(cfgEl.textContent || '{}')); } catch (e) { /* keep defaults */ }
        }
        configLoaded = true;
        // Publish a safe, non-sensitive snapshot for debugging the request path.
        var diag = ensureDiagnostics();
        diag.endpoints = redactEndpointMap(CFG.endpoints);
        diag.endpointErrors = CFG.endpointErrors || {};
    }

    function endpoint(name) {
        ensureConfig();
        return CFG.endpoints && CFG.endpoints[name] ? CFG.endpoints[name] : null;
    }

    /* ── Diagnostics (safe, non-sensitive) ───────────────────────────
     * Exposed as window.GuardianDiagnostics so the request path can be
     * inspected in the console. It shows which LOCAL TYPO3 AJAX endpoints this
     * module resolved, with CSRF tokens redacted; nothing about entitlement,
     * and no vendor request, is recorded here. */
    function ensureDiagnostics() {
        if (!window.GuardianDiagnostics) {
            window.GuardianDiagnostics = { endpoints: null, endpointErrors: null };
        }
        return window.GuardianDiagnostics;
    }

    function redactToken(url) {
        return String(url == null ? '' : url).replace(/([?&](?:token|__trustedProperties)=)[^&]*/gi, '$1[redacted]');
    }

    function redactEndpointMap(map) {
        var out = {};
        if (map) { Object.keys(map).forEach(function (k) { out[k] = redactToken(map[k]); }); }
        return out;
    }

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body ? JSON.stringify(body) : '{}'
        }).then(function (r) { return r.json(); });
    }

    /* ── Tab system ──────────────────────────────────────────────── */

    function applyLicenseGating() {
        var el = root();
        if (el) {
            el.classList.toggle('updater-not-pro', !CFG.pro);
            el.classList.toggle('updater-no-license', !CFG.licensed);
        }
        renderPlan(CFG.pro, CFG.licensed);

        var locked = [];
        if (!CFG.pro) { locked = locked.concat(PRO_TABS); }
        if (!CFG.licensed) { locked = locked.concat(LICENSED_TABS); }
        locked.forEach(function (tab) {
            var panel = document.querySelector('.updater-tab-content[data-tab-panel="' + tab + '"]');
            if (panel) { panel.classList.remove('active'); }
        });
        var active = document.querySelector('.updater-tab-btn.active');
        if (active && locked.indexOf(active.dataset.tab) !== -1) {
            switchTab(DEFAULT_TAB, false);
        }
    }

    function switchTab(name, updateHash) {
        if (VALID_TABS.indexOf(name) === -1) { name = DEFAULT_TAB; }
        if (PRO_TABS.indexOf(name) !== -1 && !CFG.pro) { openUpgradeModal('pro'); return; }
        if (LICENSED_TABS.indexOf(name) !== -1 && !CFG.licensed) { openUpgradeModal('none'); return; }

        document.querySelectorAll('.updater-tab-btn').forEach(function (btn) {
            btn.classList.toggle('active', btn.dataset.tab === name);
        });
        document.querySelectorAll('.updater-tab-content').forEach(function (panel) {
            panel.classList.toggle('active', panel.dataset.tabPanel === name);
        });

        if (updateHash) {
            try { history.replaceState(null, '', '#tab=' + name); }
            catch (e) { window.location.hash = '#tab=' + name; }
        }
    }

    function initTabs() {
        var fromHash = (window.location.hash || '').replace(/^#tab=/, '');
        var initial = VALID_TABS.indexOf(fromHash) !== -1 ? fromHash : DEFAULT_TAB;
        switchTab(initial, false);
        window.addEventListener('hashchange', function () {
            var tab = (window.location.hash || '').replace(/^#tab=/, '');
            if (VALID_TABS.indexOf(tab) !== -1) { switchTab(tab, false); }
        });
    }

    /* ── Plan badge ──────────────────────────────────────────────── */

    function renderPlan(pro, licensed) {
        var badge = byId('updaterPlanBadge');
        var tagline = byId('updaterPlanTagline');
        var list = byId('updaterPlanFeatures');
        if (!badge || !tagline || !list) { return; }

        var cls, label, msg;
        if (pro) {
            cls = 'pro'; label = '⭐ ' + t('js.plan.pro'); msg = t('js.plan.proDescription');
        } else if (licensed) {
            cls = 'free'; label = '🆓 ' + t('js.plan.free'); msg = t('js.plan.freeDescription');
        } else {
            cls = 'none'; label = '🚫 ' + t('js.plan.none'); msg = t('js.plan.noneDescription');
        }
        badge.className = 'updater-plan-badge ' + cls;
        badge.textContent = label;
        tagline.textContent = msg;

        var rows = [
            [t('feature.manualBackup'), licensed], [t('feature.updateJobs'), pro],
            [t('feature.recovery'), pro], [t('feature.scheduledBackups'), pro],
            [t('feature.recoveryPanel'), pro]
        ];
        list.innerHTML = rows.map(function (r) {
            return '<li class="' + (r[1] ? 'on' : 'off') + '">' + (r[1] ? '✓' : '🔒') + ' ' + esc(r[0]) + '</li>';
        }).join('');
    }

    /* ── Upgrade modal ───────────────────────────────────────────── */

    function openUpgradeModal(tier) {
        var m = byId('updaterUpgradeModal');
        var title = byId('updaterUpgradeTitle');
        var body = byId('updaterUpgradeBody');
        if (!m) { return; }
        if (title && body) {
            if (tier === 'none') {
                title.textContent = '🔒 ' + t('js.modal.freeRequiredTitle'); body.textContent = t('js.modal.freeRequiredBody');
            } else {
                title.textContent = '🔒 ' + t('js.modal.proRequiredTitle'); body.textContent = t('js.modal.proRequiredBody');
            }
        }
        m.classList.add('open');
    }

    function closeUpgradeModal() {
        var m = byId('updaterUpgradeModal');
        if (m) { m.classList.remove('open'); }
    }

    /* ── Update modal (real update) ──────────────────────────────── */

    function openUpdateModal() { var m = byId('updaterUpdateModal'); if (m) { m.style.display = 'flex'; } populateSelectivePackages(); }
    function closeUpdateModal() { var m = byId('updaterUpdateModal'); if (m) { m.style.display = 'none'; } }
    function onUpdateModeChange() {
        var sel = document.querySelector('input[name="updateMode"]:checked');
        var box = byId('updaterSelectivePackages');
        if (box) { box.style.display = (sel && sel.value === 'selective') ? 'block' : 'none'; }
    }

    /* ── Pre-update analysis (read-only) ─────────────────────────── */

    var analysisRequestSequence = 0;
    function runAnalysis() {
        var url = endpoint('analyse'); if (!url) { return; }
        var requestId = ++analysisRequestSequence;
        var btn = byId('updaterAnalyseBtn');
        var result = byId('updaterAnalysisResult');
        if (btn) { btn.disabled = true; btn.textContent = '⏳ ' + t('js.analysis.running'); }
        if (result) { result.innerHTML = '<div style="color:var(--updater-text-soft);padding:.8rem;">' + esc(t('js.pleaseWait')) + '</div>'; }

        postJson(url).then(function (data) {
            if (requestId !== analysisRequestSequence) { return; }
            if (!data.success) {
                result.innerHTML = '<div class="updater-result-summary error">❌ ' + esc(t('js.error')) + ': ' + esc(data.error || t('js.unknownError')) + '</div>';
                return;
            }
            var r = data.result, summary = r.summary;
            var cls = summary.errors > 0 ? 'error' : (summary.warnings > 0 ? 'warning' : 'ok');
            var html = '<div class="updater-result-summary ' + cls + '">' + esc(analysisText(summary.message, summary.message)) + '<br>'
                + '<span style="font-weight:normal;font-size:.85rem;">✅ ' + summary.ok + ' OK · ⚠️ ' + summary.warnings
                + ' ' + esc(t('js.warnings')) + ' · ❌ ' + summary.errors + ' ' + esc(t('js.errors')) + '</span></div>';
            for (var key in r.checks) {
                if (!Object.prototype.hasOwnProperty.call(r.checks, key)) { continue; }
                var c = r.checks[key];
                var icon = c.status === 'ok' ? '✅' : (c.status === 'warning' ? '⚠️' : '❌');
                html += '<div class="updater-check-row"><div class="updater-check-icon">' + icon + '</div>'
                    + '<div class="updater-check-body"><div class="updater-check-label">' + esc(analysisText(c.label, c.label)) + '</div>'
                    + '<div class="updater-check-message">' + esc(analysisText(c.message, c.message)) + '</div></div></div>';
            }
            result.innerHTML = html;
        }).catch(function (e) {
            if (requestId !== analysisRequestSequence) { return; }
            result.innerHTML = '<div class="updater-result-summary error">❌ ' + esc(e.message || e) + '</div>';
        }).finally(function () {
            if (btn) { btn.disabled = false; btn.textContent = '🔍 ' + t('js.analysis.rerun'); }
        });
    }

    /* ── Packages (read-only) ────────────────────────────────────── */

    function detectSemver(current, latest) {
        if (!current || !latest) { return ''; }
        var a = current.split('.'), b = latest.split('.');
        if (a[0] !== b[0]) { return 'semver-major'; }
        if (a[1] !== b[1]) { return 'semver-minor'; }
        return 'semver-patch';
    }

    function renderPkgRow(p) {
        var nameCls = 'updater-pkg-name';
        var status;
        if (p.abandoned) {
            status = '<span class="updater-pkg-tag updater-pkg-tag-abandoned">Abandoned</span>';
        } else if (p.has_update) {
            status = '<span class="updater-pkg-tag updater-pkg-tag-update">Update</span>';
        } else {
            status = '<span class="updater-pkg-tag updater-pkg-tag-uptodate">' + esc(t('js.current')) + '</span>';
        }
        var avail = p.latest
            ? '<span class="updater-pkg-newversion ' + detectSemver(p.current, p.latest) + '">' + esc(p.latest) + '</span>'
            : '<span class="updater-pkg-arrow">—</span>';
        return '<tr><td><span class="' + nameCls + '">' + esc(p.name) + '</span></td>'
            + '<td><span class="updater-pkg-version">' + esc(p.current) + '</span></td>'
            + '<td>' + avail + '</td><td>' + status + '</td></tr>';
    }

    function renderPackages(stats) {
        var result = byId('updaterPkgResult');
        if (!result) { return; }
        var filter = ((byId('updaterPkgFilter') || {}).value || '').toLowerCase();
        var onlyUpdates = (byId('updaterPkgOnlyUpdates') || {}).checked;
        var list = packages;
        if (filter) { list = list.filter(function (p) { return p.name.toLowerCase().indexOf(filter) !== -1; }); }
        if (onlyUpdates) { list = list.filter(function (p) { return p.has_update; }); }

        var metaHtml = '';
        if (stats) {
            metaHtml = '<div class="updater-pkg-meta">' + esc(t('js.packages.total')) + ': <strong>' + stats.total + '</strong> · '
                + esc(t('js.packages.updates')) + ': <strong>' + stats.updates + '</strong> · Abandoned: <strong>' + stats.abandoned + '</strong>'
                + (stats.cached ? ' <span style="color:var(--updater-text-soft);">' + esc(t('js.cached')) + '</span>' : '') + '</div>';
        }
        if (list.length === 0) {
            result.innerHTML = metaHtml + '<div class="updater-empty">' + esc(t('js.packages.noMatch')) + '</div>';
            return;
        }
        result.innerHTML = metaHtml + '<table class="updater-pkg-table"><thead><tr>'
            + '<th>' + esc(t('js.package')) + '</th><th style="width:120px;">' + esc(t('js.current')) + '</th><th style="width:160px;">' + esc(t('js.available')) + '</th><th style="width:140px;">Status</th>'
            + '</tr></thead><tbody>' + list.map(renderPkgRow).join('') + '</tbody></table>';
    }

    function loadPackages() {
        var url = endpoint('packages'); if (!url) { return; }
        var loadBtn = byId('updaterPkgLoadBtn');
        var result = byId('updaterPkgResult');
        if (loadBtn) { loadBtn.disabled = true; loadBtn.textContent = '⏳ ' + t('js.loading'); }
        if (result) { result.innerHTML = '<div style="color:var(--updater-text-soft);padding:.8rem;">' + esc(t('js.packages.loading')) + '</div>'; }

        postJson(url).then(function (data) {
            if (!data.success) {
                result.innerHTML = '<div class="updater-result-summary error">❌ ' + esc(data.error || t('js.error')) + '</div>';
                return;
            }
            packages = data.packages || [];
            ['updaterPkgFilter', 'updaterPkgOnlyUpdatesLabel'].forEach(function (id) {
                var el = byId(id); if (el) { el.style.display = ''; }
            });
            renderPackages(data.stats);
        }).catch(function (e) {
            result.innerHTML = '<div class="updater-result-summary error">❌ ' + esc(e.message || e) + '</div>';
        }).finally(function () {
            if (loadBtn) { loadBtn.disabled = false; loadBtn.textContent = '📦 ' + t('js.packages.reload'); }
        });
    }

    /* ── Dashboard: Manage installed packages ─────────────────────
     *
     * The enriched package manager. It loads the server-side read model (which
     * classifies each package and decides — with a precise reason — whether
     * Update / Disable / Enable / Remove may be performed), renders a filterable
     * table, and drives the Update and Remove actions through the SAME safety
     * pipeline as the updater: a required dry run whose impact is shown, then an
     * explicit confirmation, then the real job (mandatory backup → maintenance →
     * composer → extension setup → caches → verify → rollback on failure). It is
     * self-contained: any failure here surfaces inside its own panel and never
     * breaks the rest of the Dashboard. */

    var MANAGE = {
        list: [],
        mutationsAllowed: false,
        operationInProgress: false,
        loaded: false,
        pending: null,
        sticky: {}, // per-package status HTML, re-applied after a list refresh
        poll: { active: false, inFlight: false, offset: 0, phase: null, kind: null, pkg: null, log: '' }
    };

    // Monotonic operation token: a response from an older click is ignored so a
    // stale reply can never write into a (possibly different) extension's panel.
    var OPSEQ = 0;

    function scrollIntoViewIfNeeded(el) {
        if (!el || !el.getBoundingClientRect) { return; }
        var r = el.getBoundingClientRect();
        if (r.top < 0 || r.bottom > (window.innerHeight || document.documentElement.clientHeight)) {
            try { el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) { el.scrollIntoView(); }
        }
    }
    function pkgStatusPrefix(pkg) { return 'pkgstatus-' + cssId(pkg); }
    function openRowStatus(pkg) {
        var row = byId(pkgStatusPrefix(pkg) + '-row');
        if (row) { row.style.display = ''; }
        return byId(pkgStatusPrefix(pkg));
    }
    function captureSticky(pkg) {
        var el = byId(pkgStatusPrefix(pkg));
        if (el) { MANAGE.sticky[pkg] = el.innerHTML; }
    }
    function clearSticky(pkg) { delete MANAGE.sticky[pkg]; }

    var MANAGE_REASON_EN = {
        core_update_use_full: 'This is a TYPO3 core package — use the full TYPO3 update, not an individual package update.',
        core_cannot_remove: 'TYPO3 core packages cannot be removed individually.',
        core_cannot_disable: 'TYPO3 core packages cannot be disabled.',
        guardian_self: 'Guardian cannot update, disable or remove itself.',
        transitive_dependency: 'This is an indirect dependency — remove the package that requires it instead.',
        required_by_other: 'Another installed package still requires this one.',
        not_an_extension: 'This package is not a TYPO3 extension.',
        composer_managed_use_remove: 'Composer-managed packages cannot be safely disabled at runtime — use Remove instead.',
        already_active: 'The extension is already active.',
        already_disabled: 'The extension is already disabled.',
        system_cannot_remove: 'TYPO3 system extensions cannot be removed individually.',
        protected_package: 'TYPO3 protects this package from being disabled.',
        disable_unavailable: 'Disabling extensions is not available in this environment.',
        enable_unavailable: 'Enabling extensions is not available in this environment.',
        disable_unsupported: 'TYPO3 did not allow this extension to be disabled.',
        enable_unsupported: 'TYPO3 did not allow this extension to be enabled.',
        operation_in_progress: 'Another Guardian operation is currently running.',
        not_installed: 'The package is not installed.',
        pro_required: 'This action requires a Pro license.',
        // TER / compatibility (per-result states)
        composer_identity_available: 'A Composer package identity is available.',
        composer_identity_missing: 'No Composer package identity is available for this extension.',
        composer_identity_unavailable: 'No Composer package identity is available for this extension.',
        typo3_incompatible: 'Not compatible with the installed TYPO3 version.',
        php_incompatible: 'Not compatible with the installed PHP version.',
        installable: 'Ready to install.',
        metadata_unavailable: 'Compatibility metadata is unavailable.',
        incompatible_typo3: 'This extension is not compatible with the installed TYPO3 version.',
        incompatible_php: 'This extension is not compatible with the installed PHP version.',
        already_installed: 'This extension is already installed.',
        ter_invalid_query: 'The search term is invalid.',
        ter_not_found: 'The extension was not found in the TER.',
        ter_http_error: 'The TER responded with an error.',
        ter_transport_error: 'The TER could not be reached.',
        ter_invalid_response: 'The TER returned an unreadable response.',
        ter_untrusted_endpoint: 'Refused a non-trusted TER endpoint.',
        ter_invalid_extension_key: 'The extension key is invalid.',
        ter_error: 'The TER request failed.',
        ter_http_error: 'The TER responded with an error status.',
        packagist_http_error: 'Packagist responded with an error status.',
        ter_rate_limited: 'The TER is rate-limiting requests — try again shortly.',
        packagist_rate_limited: 'Packagist is rate-limiting requests — try again shortly.',
        dns_failure: 'The extension service could not be resolved (DNS failure).',
        tls_failure: 'A secure (TLS) connection to the extension service could not be established.',
        timeout: 'The extension service did not respond in time (timeout).',
        service_unreachable: 'The extension service could not be reached.',
        transport_error: 'The extension service could not be contacted.',
        unsupported_schema: 'The extension service returned an unsupported response format.',
        untrusted_endpoint: 'Refused a non-trusted extension-service endpoint.',
        invalid_self_target: 'This operation only applies to Guardian itself.',
        self_maintenance_running: 'A Guardian self-maintenance operation is already running.',
        guardian_not_root_require: 'Guardian is not a direct Composer requirement in this installation.',
        confirm_phrase_mismatch: 'The confirmation phrase did not match.',
        system_maintainer_required: 'This action requires TYPO3 system-maintainer access.',
        // Upload / archive
        upload_incomplete: 'The upload did not complete.',
        upload_missing: 'No uploaded file was found.',
        upload_too_large: 'The uploaded file exceeds the maximum allowed size.',
        upload_not_zip: 'Only ZIP archives are accepted.',
        upload_store_failed: 'The upload could not be stored.',
        upload_move_failed: 'The uploaded file could not be moved into the staging area.',
        upload_processing_error: 'The upload could not be processed. The exact cause was written to the Guardian log.',
        no_file_field: 'No file field was received by the server.',
        upload_root_creation_failed: 'Guardian could not create its private extension-upload directory.',
        upload_root_not_writable: 'Guardian cannot write to its private extension-upload directory.',
        upload_directory_creation_failed: 'Guardian could not create the individual upload directory.',
        upload_destination_invalid: 'The upload destination is outside the Guardian upload root.',
        upload_stream_copy_failed: 'The uploaded file could not be copied into the staging area.',
        upload_size_mismatch: 'The stored file size did not match the uploaded size.',
        upload_disk_space_insufficient: 'There is not enough free disk space to stage the upload.',
        staging_unwritable: 'The private staging directory is not writable.',
        staging_not_found: 'The staged upload was not found.',
        staging_invalid_token: 'The staging token is invalid.',
        zip_invalid: 'The archive is not a valid ZIP file.',
        zip_empty: 'The archive is empty.',
        zip_too_many_entries: 'The archive contains too many files.',
        zip_null_byte: 'The archive contains an illegal file name.',
        zip_unsafe_path: 'The archive contains an unsafe path.',
        zip_entry_too_large: 'A file in the archive is too large.',
        zip_bomb_ratio: 'The archive looks like a decompression bomb.',
        zip_expanded_too_large: 'The archive expands to too much data.',
        zip_unsafe_symlink: 'The archive contains an unsafe symlink.',
        zip_extract_failed: 'The archive could not be extracted.',
        zip_extension_unavailable: 'The PHP zip extension is not available.',
        // Inspection / install
        no_extension_root: 'No valid extension was found in the archive.',
        multiple_roots: 'The archive contains multiple unrelated extensions.',
        invalid_composer_json: 'The extension has a malformed composer.json.',
        invalid_composer_name: 'The extension has an invalid Composer package name.',
        no_valid_identity: 'The extension has no valid identity.',
        extension_version_unknown: 'The extension version could not be determined from composer.json, ext_emconf.php or the filename.',
        would_overwrite_guardian: 'This package would overwrite Guardian.',
        conflicts_typo3_core: 'This package conflicts with the TYPO3 core.',
        suspicious_files: 'The extension contains suspicious or unsupported files.',
        extension_not_installable: 'This extension cannot be installed automatically.',
        fingerprint_mismatch: 'The staged files changed since analysis — please inspect again.',
        target_exists_unmanaged: 'A package directory already exists and is not Guardian-managed.'
    };

    var MANAGE_TXT_EN = {
        reload: 'Reload packages', total: 'Installed packages', shown: 'Shown',
        sourceType: 'Type / source', installed: 'Installed', availableCol: 'Update status',
        state: 'State', actions: 'Actions', active: 'Active', disabled: 'Disabled',
        upToDate: 'Up to date', busy: 'Another Guardian operation is running — actions are temporarily disabled.',
        proRequired: 'Update and Remove require a Pro license — the list is read-only.',
        'source.local': 'Local path', 'source.composer': 'Composer',
        'action.update': 'Update', 'action.disable': 'Disable', 'action.enable': 'Enable', 'action.remove': 'Remove',
        dryRunning: 'Analysing (dry run)…', applying: 'Applying changes…',
        dryOk: 'Dry run passed — review the impact above, then confirm.',
        confirmUpdate: 'Confirm update', confirmRemove: 'Confirm removal', cancel: 'Cancel',
        snapshotVendor: 'Include vendor/ in the safety backup (recommended)',
        deleteManagedSource: 'Remove package and delete Guardian-managed source files',
        ownershipVerified: 'Guardian ownership verified',
        ownershipNotVerified: 'Guardian ownership could not be verified',
        sourceRetained: 'The package was removed, but its source directory was retained because Guardian could not verify ownership.',
        removalRollback: 'If any step fails, Guardian restores the source directory, composer.json, composer.lock, the version mapping and the package registration from the safety backup.',
        sourceDirectory: 'Source directory',
        composerRegistrationRemoval: 'Composer registration will be removed',
        sourceDirRemoval: 'Guardian-managed source files will be deleted',
        safetyBackupNotice: 'A mandatory safety backup is created before any change.',
        removalComposerRemoved: 'Composer package removed',
        removalExtensionRemoved: 'TYPO3 extension removed',
        removalSourceRemoved: 'Source directory removed',
        removalSourceRetained: 'Source directory retained',
        removalOwnershipRemoved: 'Ownership metadata removed',
        removalSafetyBackup: 'Safety backup',
        done: 'Completed successfully.', failed: 'Failed', rolledBack: 'Rolled back to the safety backup.',
        confirmDisable: 'Disable this extension now? It can be re-enabled at any time.',
        confirmEnable: 'Enable this extension now?',
        guardianActiveHint: 'Guardian is the currently active extension; this runs safely through the deferred self-maintenance worker after the request.',
        working: 'Working…', details: 'Details', close: 'Close',
        role: 'Dependency', constraint: 'Constraint', latestOverall: 'Latest overall', dependencyImpact: 'Dependency impact',
        'role.root': 'Direct (root require)', 'role.transitive': 'Transitive dependency',
        'cat.typo3_core': 'TYPO3 core', 'cat.typo3_system_extension': 'TYPO3 system extension',
        'cat.third_party_extension': 'Third-party extension', 'cat.local_extension': 'Local extension',
        'cat.composer_library': 'Composer library',
        'ustate.up_to_date': 'Up to date', 'ustate.update_available': 'Update available',
        'ustate.major_update_available': 'Major update available', 'ustate.incompatible_update': 'Incompatible update',
        'ustate.metadata_unavailable': 'Metadata unavailable', 'ustate.update_check_failed': 'Update check failed'
    };

    function mreason(code) {
        if (!code) { return ''; }
        var tr = t('js.pkg.reason.' + code);
        if (tr !== 'js.pkg.reason.' + code) { return tr; }
        return MANAGE_REASON_EN[code] || code;
    }

    function mtxt(key, fallback) {
        var tr = t('js.pkg.' + key);
        if (tr !== 'js.pkg.' + key) { return tr; }
        return MANAGE_TXT_EN[key] || fallback || key;
    }

    function classificationLabel(c) {
        var tr = t('dashboard.packages.classification.' + c);
        if (tr !== 'dashboard.packages.classification.' + c) { return tr; }
        return c === 'core' ? 'TYPO3 core' : (c === 'custom' ? 'Custom / local' : 'Third-party');
    }

    function loadDashboardPackages() {
        var url = endpoint('dashboardPackages');
        var box = byId('updaterPkgResult');
        var loadBtn = byId('updaterPkgLoadBtn');
        if (!url || !box) { return; }
        if (loadBtn) { loadBtn.disabled = true; loadBtn.textContent = '⏳ ' + t('js.loading'); }
        box.innerHTML = '<div style="color:var(--updater-text-soft);padding:.8rem;">' + esc(t('js.packages.loading')) + '</div>';
        postJson(url).then(function (d) {
            if (!d || d.success !== true) {
                box.innerHTML = '<div class="updater-result-summary error">❌ ' + esc((d && d.error) || t('js.error')) + '</div>';
                return;
            }
            MANAGE.list = d.packages || [];
            MANAGE.mutationsAllowed = d.mutationsAllowed === true;
            MANAGE.operationInProgress = d.operationInProgress === true;
            MANAGE.loaded = true;
            ['updaterPkgFilter', 'updaterPkgSource', 'updaterPkgOnlyUpdatesLabel'].forEach(function (id) {
                var el = byId(id); if (el) { el.style.display = ''; }
            });
            renderManage();
        }).catch(function (e) {
            box.innerHTML = '<div class="updater-result-summary error">❌ ' + esc((e && e.message) || e) + '</div>';
        }).finally(function () {
            if (loadBtn) { loadBtn.disabled = false; loadBtn.textContent = '📦 ' + mtxt('reload', 'Reload packages'); }
        });
    }

    function manageFilteredList() {
        var filter = ((byId('updaterPkgFilter') || {}).value || '').toLowerCase();
        var source = (byId('updaterPkgSource') || {}).value || '';
        var onlyUpdates = (byId('updaterPkgOnlyUpdates') || {}).checked;
        return MANAGE.list.filter(function (p) {
            if (filter) {
                var hay = (p.name + ' ' + (p.extension_key || '')).toLowerCase();
                if (hay.indexOf(filter) === -1) { return false; }
            }
            if (source && p.classification !== source) { return false; }
            if (onlyUpdates && !p.has_update) { return false; }
            return true;
        });
    }

    function categoryLabel(c) {
        return mtxt('cat.' + c, c);
    }
    function updateStateLabel(code) {
        return mtxt('ustate.' + (code || 'metadata_unavailable'), code || '');
    }

    // Renders an action ONLY when it is applicable to this package class. An
    // applicable-but-not-permitted action is rendered disabled with its reason;
    // a non-applicable action (e.g. Disable on a library) is not rendered at all.
    function manageActionButton(kind, pkg, action, icon, labelKey, labelFallback) {
        if (!action || action.applicable !== true) { return ''; }
        var label = mtxt(labelKey, labelFallback);
        var permitted = action.permitted === true && MANAGE.mutationsAllowed;
        if (permitted) {
            return '<button type="button" class="updater-btn updater-btn-sm" data-action="manage-' + kind + '" data-package="' + esc(pkg.name) + '">' + icon + ' ' + esc(label) + '</button>';
        }
        var why = MANAGE.mutationsAllowed ? mreason(action.reason) : mreason('pro_required');
        return '<button type="button" class="updater-btn updater-btn-sm" disabled title="' + esc(why) + '">' + icon + ' ' + esc(label) + '</button>';
    }

    function updateStatusCell(p) {
        var st = p.update_state || 'metadata_unavailable';
        var cls = st === 'up_to_date' ? 'updater-pkg-uptodate'
            : (st === 'update_available' || st === 'major_update_available') ? 'updater-pkg-arrow'
            : 'updater-pkg-src';
        var version = p.has_update && p.latest ? ' <span class="updater-pkg-version">' + esc(p.current) + ' → ' + esc(p.latest) + '</span>' : '';
        return '<span class="' + cls + '">' + esc(updateStateLabel(st)) + '</span>' + version;
    }

    function manageRow(p) {
        var actions = p.actions || {};
        var srcLabel = p.source === 'local-path' ? mtxt('source.local', 'Local path') : mtxt('source.composer', 'Composer');
        var stateBadge = p.active
            ? '<span class="updater-status-badge updater-status-ok">' + esc(mtxt('active', 'Active')) + '</span>'
            : '<span class="updater-status-badge updater-status-idle">' + esc(mtxt('disabled', 'Disabled')) + '</span>';
        var extKey = p.extension_key ? '<div class="updater-pkg-extkey">' + esc(p.extension_key) + '</div>' : '';
        if (p.abandoned) { extKey += '<div class="updater-pill updater-pill-core">' + esc(mtxt('ustate.abandoned', 'Abandoned')) + '</div>'; }

        var buttons = [];
        buttons.push(manageActionButton('update', p, actions.update, '⬆️', 'action.update', 'Update'));
        buttons.push(manageActionButton('disable', p, actions.disable, '⏸️', 'action.disable', 'Disable'));
        buttons.push(manageActionButton('enable', p, actions.enable, '▶️', 'action.enable', 'Enable'));
        buttons.push(manageActionButton('remove', p, actions.remove, '🗑️', 'action.remove', 'Remove'));
        // Guardian uses the SAME visible labels (Disable / Remove) as every other
        // extension — the destructive red styling and the safer deferred
        // self-maintenance workflow are keyed on package IDENTITY (is_guardian),
        // never on the button text.
        if (p.is_guardian && MANAGE.mutationsAllowed) {
            var gHint = ' title="' + esc(mtxt('guardianActiveHint', 'Guardian is the active extension; this runs safely after the request.')) + '"';
            buttons.push('<button type="button" class="updater-btn updater-btn-sm updater-btn-danger" data-action="guardian-self-disable" data-package="' + esc(p.name) + '"' + gHint + '>⏸️ ' + esc(mtxt('action.disable', 'Disable')) + '</button>');
            buttons.push('<button type="button" class="updater-btn updater-btn-sm updater-btn-danger" data-action="guardian-uninstall" data-package="' + esc(p.name) + '"' + gHint + '>🗑️ ' + esc(mtxt('action.remove', 'Remove')) + '</button>');
        }
        buttons.push('<button type="button" class="updater-btn updater-btn-sm updater-btn-secondary" data-action="manage-details" data-package="' + esc(p.name) + '">ℹ️ ' + esc(mtxt('details', 'Details')) + '</button>');
        var visible = buttons.filter(function (b) { return b !== ''; });

        return '<tr>'
            + '<td><span class="updater-pkg-name">' + esc(p.name) + '</span>' + extKey + '</td>'
            + '<td><span class="updater-pill updater-pill-' + esc(p.classification) + '">' + esc(categoryLabel(p.category)) + '</span><div class="updater-pkg-src">' + esc(srcLabel) + ' · ' + esc(mtxt('role.' + (p.is_root ? 'root' : 'transitive'), '')) + '</div></td>'
            + '<td><span class="updater-pkg-version">' + esc(p.current) + '</span></td>'
            + '<td>' + updateStatusCell(p) + '</td>'
            + '<td>' + stateBadge + '</td>'
            + '<td class="updater-pkg-actions">' + visible.join(' ') + '</td>'
            + '</tr>'
            + '<tr class="updater-pkg-detailrow" id="manage-detail-' + cssId(p.name) + '" style="display:none;"><td colspan="6"></td></tr>'
            + '<tr class="updater-pkg-statusrow" id="' + pkgStatusPrefix(p.name) + '-row" style="display:none;"><td colspan="6"><div id="' + pkgStatusPrefix(p.name) + '"></div></td></tr>';
    }

    function cssId(name) { return String(name).replace(/[^a-z0-9]+/gi, '-'); }

    function manageDetails(el) {
        var name = el.dataset.package;
        var p = MANAGE.list.filter(function (x) { return x.name === name; })[0];
        var row = byId('manage-detail-' + cssId(name));
        if (!p || !row) { return; }
        if (row.style.display !== 'none') { row.style.display = 'none'; return; }
        var actions = p.actions || {};
        var reasons = [];
        ['update', 'disable', 'enable', 'remove'].forEach(function (k) {
            var a = actions[k];
            if (a && a.applicable === true && a.permitted !== true && a.reason) {
                reasons.push('<li><strong>' + esc(mtxt('action.' + k, k)) + ':</strong> ' + esc(mreason(a.reason)) + '</li>');
            }
        });
        var rows = ''
            + '<div class="updater-pkg-meta">' + esc(mtxt('cat.' + p.category, p.category)) + ' · ' + esc(mtxt('role.' + (p.is_root ? 'root' : 'transitive'), '')) + '</div>'
            + '<div class="updater-pkg-meta">' + esc(mtxt('installed', 'Installed')) + ': <strong>' + esc(p.current) + '</strong>'
            + ' · ' + esc(mtxt('latestOverall', 'Latest overall')) + ': <strong>' + esc(p.latest_overall || p.latest || '—') + '</strong>'
            + (p.constraint ? ' · ' + esc(mtxt('constraint', 'Constraint')) + ': <strong>' + esc(p.constraint) + '</strong>' : '')
            + (p.extension_key ? ' · key: <strong>' + esc(p.extension_key) + '</strong>' : '') + '</div>'
            + (reasons.length ? '<div class="updater-inline-msg"><strong>' + esc(mtxt('dependencyImpact', 'Dependency impact')) + ':</strong><ul style="margin:.3rem 0 0 1rem;">' + reasons.join('') + '</ul></div>' : '');
        var cell = row.querySelector('td');
        if (cell) { cell.innerHTML = '<div class="updater-card" style="padding:.7rem 1rem;margin:.3rem 0;">' + rows + '</div>'; }
        row.style.display = '';
    }

    function renderManage() {
        var box = byId('updaterPkgResult');
        if (!box || !MANAGE.loaded) { return; }
        var list = manageFilteredList();
        var notice = '';
        if (MANAGE.operationInProgress) {
            notice += '<div class="updater-result-summary idle">⏳ ' + esc(mtxt('busy')) + '</div>';
        }
        if (!MANAGE.mutationsAllowed) {
            notice += '<div class="updater-result-summary idle">🔒 ' + esc(mtxt('proRequired')) + '</div>';
        }
        var meta = '<div class="updater-pkg-meta">' + esc(mtxt('total', 'Installed packages')) + ': <strong>' + MANAGE.list.length + '</strong> · '
            + esc(mtxt('shown', 'Shown')) + ': <strong>' + list.length + '</strong></div>';
        var table;
        if (!list.length) {
            table = '<div class="updater-empty">' + esc(t('js.packages.noMatch')) + '</div>';
        } else {
            table = '<div style="overflow-x:auto;"><table class="updater-pkg-table"><thead><tr>'
                + '<th>' + esc(t('js.package')) + '</th>'
                + '<th style="width:150px;">' + esc(mtxt('sourceType', 'Type / source')) + '</th>'
                + '<th style="width:110px;">' + esc(mtxt('installed', 'Installed')) + '</th>'
                + '<th style="width:130px;">' + esc(mtxt('availableCol', 'Available')) + '</th>'
                + '<th style="width:90px;">' + esc(mtxt('state', 'State')) + '</th>'
                + '<th style="width:260px;">' + esc(mtxt('actions', 'Actions')) + '</th>'
                + '</tr></thead><tbody>' + list.map(manageRow).join('') + '</tbody></table></div>';
        }
        box.innerHTML = notice + meta + table;
        // Re-attach any per-row status panel so a list refresh never erases it.
        // A currently filtered-out row keeps its sticky entry for when it returns.
        Object.keys(MANAGE.sticky).forEach(function (pkg) {
            var el = openRowStatus(pkg);
            if (el) { el.innerHTML = MANAGE.sticky[pkg]; }
        });
    }

    function managePkgError(d) {
        if (d && d.reason) { return mreason(d.reason); }
        return (d && d.error) || t('js.error');
    }

    // The manage Update/Remove flow renders into the affected row's own status
    // panel (never a single top-of-list panel) and drives the shared gjob poller
    // with a per-row prefix. Results are captured as "sticky" HTML so a list
    // refresh re-attaches them to the correct row instead of erasing them.

    function manageStart(el) {
        var kind = el.dataset.action === 'manage-remove' ? 'remove' : 'update';
        var pkg = el.dataset.package;
        if (!pkg || GJOB.active) { return; }
        var dryUrl = endpoint(kind === 'remove' ? 'packageRemoveDryRun' : 'packageUpdateDryRun');
        var prefix = pkgStatusPrefix(pkg);
        var panel = openRowStatus(pkg);
        if (!dryUrl || !panel) { return; }
        var op = ++OPSEQ;
        MANAGE.pending = { pkg: pkg, kind: kind, prefix: prefix };
        clearSticky(pkg);
        gjobRenderPanel(prefix, (kind === 'remove' ? mtxt('action.remove') : mtxt('action.update')) + ' · ' + pkg, xtxt('analyzing'));
        scrollIntoViewIfNeeded(byId(prefix));
        postJson(dryUrl, { package: pkg }).then(function (d) {
            if (op !== OPSEQ) { return; }
            if (!d || d.success !== true) { MANAGE.pending = null; gjobMsg(prefix, managePkgError(d), 'error'); captureSticky(pkg); return; }
            gjobStart(prefix, 'dry', { kind: kind, pkg: pkg, prefix: prefix, scope: 'manage', jobId: d.jobId, managedRemoval: d.managedRemoval || null }, manageJobFinished);
        }).catch(function () { if (op === OPSEQ) { MANAGE.pending = null; gjobMsg(prefix, t('js.error'), 'error'); captureSticky(pkg); } });
    }

    function manageConfirm() {
        var ctx = GJOB.ctx;
        if (!ctx || ctx.scope !== 'manage' || GJOB.active) { return; }
        var liveUrl = endpoint(ctx.kind === 'remove' ? 'packageRemoveStart' : 'packageUpdateStart');
        if (!liveUrl) { gjobMsg(ctx.prefix, t('js.error'), 'error'); return; }
        var op = ++OPSEQ;
        var snapshot = (byId(ctx.prefix + 'Snapshot') || {}).checked;
        var deleteSource = (byId(ctx.prefix + 'DeleteSource') || {}).checked === true;
        gjobRenderPanel(ctx.prefix, (ctx.kind === 'remove' ? mtxt('action.remove') : mtxt('action.update')) + ' · ' + ctx.pkg, mtxt('applying'));
        postJson(liveUrl, { package: ctx.pkg, confirm: true, snapshotVendor: snapshot !== false, deleteSource: deleteSource }).then(function (d) {
            if (op !== OPSEQ) { return; }
            if (!d || d.success !== true) { gjobMsg(ctx.prefix, managePkgError(d), 'error'); captureSticky(ctx.pkg); return; }
            gjobStart(ctx.prefix, 'live', { kind: ctx.kind, pkg: ctx.pkg, prefix: ctx.prefix, scope: 'manage', jobId: d.jobId }, manageJobFinished);
        }).catch(function () { if (op === OPSEQ) { gjobMsg(ctx.prefix, t('js.error'), 'error'); captureSticky(ctx.pkg); } });
    }

    function manageCancel() {
        var ctx = GJOB.ctx || MANAGE.pending;
        MANAGE.pending = null;
        if (ctx && ctx.pkg) {
            clearSticky(ctx.pkg);
            var el = byId(pkgStatusPrefix(ctx.pkg)); if (el) { el.innerHTML = ''; }
            var row = byId(pkgStatusPrefix(ctx.pkg) + '-row'); if (row) { row.style.display = 'none'; }
        }
    }

    function manageStateChange(el) {
        var kind = el.dataset.action === 'manage-enable' ? 'enable' : 'disable';
        var pkg = el.dataset.package;
        var url = endpoint(kind === 'enable' ? 'packageEnable' : 'packageDisable');
        if (!url || !pkg) { return; }
        if (!window.confirm(mtxt(kind === 'enable' ? 'confirmEnable' : 'confirmDisable'))) { return; }
        var prefix = pkgStatusPrefix(pkg);
        var panel = openRowStatus(pkg); if (!panel) { return; }
        var op = ++OPSEQ;
        el.disabled = true;
        clearSticky(pkg);
        gjobMsg(prefix, mtxt('working', 'Working…'), 'idle');
        scrollIntoViewIfNeeded(byId(prefix));
        postJson(url, { package: pkg, confirm: true }).then(function (d) {
            if (op !== OPSEQ) { return; }
            if (d && d.success === true) { gjobMsg(prefix, mtxt('done'), 'ok'); captureSticky(pkg); loadDashboardPackages(); }
            else { gjobMsg(prefix, managePkgError(d), 'error'); captureSticky(pkg); }
        }).catch(function () { if (op === OPSEQ) { gjobMsg(prefix, t('js.error'), 'error'); captureSticky(pkg); } });
    }

    function manageJobFinished(job) {
        var ctx = GJOB.ctx || {};
        var prefix = ctx.prefix; var pkg = ctx.pkg;
        var actions = byId(prefix + 'Actions');
        var phaseEl = byId(prefix + 'Phase');
        if (!job) { gjobMsg(prefix, t('js.error'), 'error'); captureSticky(pkg); return; }
        if (job.status !== 'succeeded') {
            if (phaseEl) { phaseEl.textContent = job.result_status === 'blocked' ? xtxt('fail.blocked', 'Blocked') : mtxt('failed'); }
            if (actions) { actions.innerHTML = jobFailureHtml(job); }
            MANAGE.pending = null; captureSticky(pkg); loadDashboardPackages();
            return;
        }
        if (GJOB.phase === 'dry') {
            if (phaseEl) { phaseEl.textContent = mtxt('dryOk'); }
            if (actions) {
                var confirmLabel = ctx.kind === 'remove' ? mtxt('confirmRemove') : mtxt('confirmUpdate');
                var extra = '';
                if (ctx.kind === 'remove' && ctx.managedRemoval && ctx.managedRemoval.managed) {
                    extra = removalPlanHtml(ctx.managedRemoval, prefix);
                }
                actions.innerHTML = extra
                    + '<label class="updater-pkg-checkbox" style="display:block;margin-bottom:.4rem;"><input type="checkbox" id="' + prefix + 'Snapshot" checked> ' + esc(mtxt('snapshotVendor')) + '</label>'
                    + '<button type="button" class="updater-btn" data-action="manage-confirm">✅ ' + esc(confirmLabel) + '</button> '
                    + '<button type="button" class="updater-btn updater-btn-secondary" data-action="manage-cancel">✖ ' + esc(mtxt('cancel')) + '</button>';
            }
            captureSticky(pkg);
            return;
        }
        if (phaseEl) { phaseEl.textContent = mtxt('done'); }
        MANAGE.pending = null;
        if (actions) {
            actions.innerHTML = ctx.kind === 'remove'
                ? removalSummaryHtml(job, ctx.managedRemoval)
                : '<div class="updater-inline-ok">✅ ' + esc(mtxt('done')) + '</div>';
        }
        captureSticky(pkg); loadDashboardPackages();
    }

    // Confirmation panel for a Guardian-managed uploaded-package removal.
    function removalPlanHtml(mr, prefix) {
        var verified = mr.ownership_verified === true;
        var rows = '<div class="updater-pkg-meta" style="margin-bottom:.5rem;">'
            + '<div><strong>' + esc(mtxt('action.remove')) + ':</strong> <code>' + esc(mr.package || '') + '</code></div>'
            + '<div>' + esc(mtxt('sourceDirectory')) + ': <code>' + esc(mr.source_relative || '—') + '</code></div>'
            + '<div>' + (verified ? '✅ ' + esc(mtxt('ownershipVerified')) : '⚠️ ' + esc(mtxt('ownershipNotVerified'))) + '</div>'
            + '<div>' + esc(mtxt('composerRegistrationRemoval')) + '</div>'
            + (verified ? '<div>' + esc(mtxt('sourceDirRemoval')) + '</div>' : '<div>' + esc(mtxt('sourceRetained')) + '</div>')
            + '<div>' + esc(mtxt('safetyBackupNotice')) + '</div>'
            + '<div class="updater-inline-msg" style="margin-top:.3rem;">' + esc(mtxt('removalRollback')) + '</div>'
            + '</div>';
        // Default-checked only when ownership is verified; disabled otherwise.
        var box = '<label class="updater-pkg-checkbox" style="display:block;margin-bottom:.4rem;">'
            + '<input type="checkbox" id="' + prefix + 'DeleteSource"' + (verified ? ' checked' : ' disabled') + '> '
            + esc(mtxt('deleteManagedSource')) + '</label>';
        return rows + box;
    }

    // Post-removal summary.
    function removalSummaryHtml(job, mr) {
        var removed = job && job.source_removed === 'removed';
        var lines = ['<div class="updater-inline-ok">✅ ' + esc(mtxt('removalComposerRemoved')) + '</div>',
            '<div class="updater-inline-ok">✅ ' + esc(mtxt('removalExtensionRemoved')) + '</div>'];
        if (mr && mr.managed) {
            lines.push('<div class="' + (removed ? 'updater-inline-ok' : 'updater-inline-msg') + '">'
                + (removed ? '✅ ' + esc(mtxt('removalSourceRemoved')) : 'ℹ️ ' + esc(mtxt('removalSourceRetained'))) + '</div>');
            if (removed) { lines.push('<div class="updater-inline-ok">✅ ' + esc(mtxt('removalOwnershipRemoved')) + '</div>'); }
        }
        if (job && job.safety_backup) {
            lines.push('<div class="updater-pkg-meta">' + esc(mtxt('removalSafetyBackup')) + ': <code>' + esc(job.safety_backup) + '</code></div>');
        }
        return lines.join('');
    }

    /* ── Extensions: TER search + custom upload ────────────────────
     *
     * Self-contained: every entry point is user-triggered (never in boot()), so a
     * failure here cannot block the rest of the module. TER install and custom
     * install both drive the SAME server-side job pipeline as updates/removals and
     * poll updateJobStatus/updateJobLog through one shared, panel-scoped poller. */

    var EXT_TXT_EN = {
        installedBadge: 'Installed', install: 'Install', searching: 'Searching the TER…',
        noResults: 'No matching extensions found.', latest: 'Latest', author: 'Author',
        updated: 'Updated', abandoned: 'Abandoned', deprecated: 'Deprecated',
        analyzing: 'Analysing…', confirmInstall: 'Confirm install',
        uploading: 'Uploading…', inspecting: 'Inspecting the package…',
        selectFirst: 'Choose a ZIP file first.', dryRun: 'Run dry run',
        installStaged: 'Install extension', identity: 'Detected extension',
        targetPath: 'Target path', blocking: 'Blocking issues', notInstallable: 'Automatic installation is not available',
        source: 'Source', composerName: 'Composer package', queryTooShort: 'Enter at least two characters.',
        noResultsTer: 'No matching TER extension was found.',
        orphanManagedDirectory: 'Orphaned managed directory', reuseExistingDirectory: 'Reuse existing directory',
        removeOrphanedDirectory: 'Remove orphaned directory', directoryConflict: 'Directory conflict',
        detectedPackage: 'Detected package',
        orphanOwnedHint: 'A Guardian-owned directory from a previous install already exists here. You can reuse it or remove it and continue.',
        orphanConflictHint: 'A different, unrelated package already occupies this directory. Guardian will not modify it automatically — remove or rename it manually, then re-upload.',
        orphanUnownedHint: 'A directory with this name already exists but Guardian cannot prove it created it. Remove or rename it manually, then re-upload.',
        removingOrphan: 'Removing the orphaned directory…', orphanRemoved: 'Orphaned directory removed — you can install now.',
        guardianDisablePrompt: 'This disables Guardian after this request finishes. Type DISABLE GUARDIAN to confirm:',
        disablePhraseHint: 'You must type DISABLE GUARDIAN exactly to confirm.',
        removePhraseHint: 'You must type REMOVE GUARDIAN exactly to confirm.',
        guardianDisableQueued: 'Disabling Guardian… you will be redirected when it finishes.',
        guardianDisabled: 'Guardian disabled. Redirecting…',
        guardianRemoveWarning: 'This removes Guardian from Composer. A safety backup is taken first; the packages/ source is never auto-deleted. After removal you will be redirected because the Guardian module no longer exists.',
        confirmRemoveGuardian: 'Confirm Guardian removal', typeRemoveGuardian: 'Type REMOVE GUARDIAN to confirm',
        removingGuardian: 'Removing Guardian…',
        'fail.blocked': 'Blocked', 'fail.detailsHeading': 'Details', 'fail.recHeading': 'Recommendations',
        'fail.exitCode': 'Composer exit code',
        'fail.summary.composer_dependency_conflict': 'The extension cannot be installed with the current dependency set.',
        'fail.summary.composer_auth_error': 'Composer could not authenticate against a package repository.',
        'fail.summary.composer_network_error': 'Composer could not reach the package repository.',
        'fail.summary.composer_timeout': 'The dependency analysis timed out.',
        'fail.summary.composer_error': 'The dependency analysis failed.',
        'fail.summary.analysis_error': 'The installation analysis could not be completed.',
        'fail.rec.rec_select_older_version': 'Select an older compatible extension version.',
        'fail.rec.rec_update_conflicting_first': 'Update the conflicting package first.',
        'fail.rec.rec_check_typo3_php': 'Check the required TYPO3 and PHP versions.',
        'fail.rec.rec_retry_later': 'Try again once the repository is reachable.',
        'fail.rec.rec_check_auth': 'Check your Composer repository authentication.',
        'upload.detailArea': 'Required writable runtime area',
        'upload.rec.upload_root_creation_failed': 'Ensure var/guardian/ is writable by the TYPO3 PHP process user and retry.',
        'upload.rec.upload_root_not_writable': 'Correct the ownership and permissions of var/guardian/extensions/uploads and retry.',
        'upload.rec.upload_directory_creation_failed': 'Ensure var/guardian/extensions/uploads is writable and retry.',
        'upload.rec.upload_destination_invalid': 'This is an internal safety block; retry the upload.',
        'upload.rec.upload_move_failed': 'Check the permissions of var/guardian/extensions/uploads and retry.',
        'upload.rec.upload_stream_copy_failed': 'Check disk space and the permissions of var/guardian/extensions/uploads, then retry.',
        'upload.rec.upload_size_mismatch': 'Re-upload the file; the transfer was incomplete.',
        'upload.rec.upload_disk_space_insufficient': 'Free disk space on the server and retry.',
        'upload.rec.upload_too_large': 'Upload a smaller archive (the limit is shown above).',
        'upload.rec.upload_not_zip': 'Upload a .zip archive of the extension.',
        'upload.rec.upload_incomplete': 'Re-select the file and upload again.',
        'upload.rec.upload_processing_error': 'Check the Guardian log for the exact cause, then retry.'
    };
    function xtxt(key, fallback) {
        var tr = t('js.ext.' + key);
        if (tr !== 'js.ext.' + key) { return tr; }
        return EXT_TXT_EN[key] || fallback || key;
    }
    function compatText(v) {
        if (v === true) { return '✓'; }
        if (v === false) { return '✕'; }
        return '—';
    }

    /* — one shared, panel-scoped job poller (dry run → confirm → live) — */
    var GJOB = { active: false, inFlight: false, offset: 0, prefix: null, phase: null, ctx: null, onFinished: null, log: '' };

    function gjobRenderPanel(prefix, title, phaseLabel) {
        var el = byId(prefix);
        if (!el) { return; }
        el.innerHTML = '<div class="updater-job-card"><div class="updater-job-status"><strong>' + esc(title) + '</strong> · <span id="' + prefix + 'Phase">' + esc(phaseLabel) + '</span></div>'
            + '<div id="' + prefix + 'Steps" class="updater-job-steps"></div>'
            + '<pre id="' + prefix + 'Log" class="updater-cron-cmd" style="max-height:240px;overflow:auto;white-space:pre-wrap;margin-top:.6rem;"></pre>'
            + '<div id="' + prefix + 'Actions" style="margin-top:.6rem;"></div></div>';
        GJOB.log = '';
    }
    function gjobMsg(prefix, msg, kind) {
        var el = byId(prefix);
        if (!el) { return; }
        var cls = kind === 'error' ? 'error' : (kind === 'ok' ? 'ok' : 'idle');
        el.innerHTML = '<div class="updater-result-summary ' + cls + '">' + esc(msg) + '</div>';
    }
    function gjobStart(prefix, phase, ctx, onFinished) {
        GJOB.active = true; GJOB.inFlight = false; GJOB.offset = 0; GJOB.prefix = prefix;
        GJOB.phase = phase; GJOB.ctx = ctx; GJOB.onFinished = onFinished; GJOB.log = '';
        gjobPoll();
    }
    function gjobAppendLog(entries) {
        if (!entries || !entries.length || !GJOB.prefix) { return; }
        entries.forEach(function (e) { GJOB.log += '[' + (e.level || 'info') + '] ' + (e.step ? (e.step + ': ') : '') + (e.msg || '') + '\n'; });
        var log = byId(GJOB.prefix + 'Log');
        if (log) { log.textContent = GJOB.log; log.scrollTop = log.scrollHeight; }
    }
    function gjobRenderJob(job) {
        if (!job || !GJOB.prefix) { return; }
        var steps = byId(GJOB.prefix + 'Steps');
        if (steps) { steps.innerHTML = stepListHtml(job, GJOB.log); }
        var phaseEl = byId(GJOB.prefix + 'Phase');
        if (phaseEl && (job.status === 'running' || job.status === 'queued')) {
            var base = GJOB.phase === 'dry' ? xtxt('analyzing', 'Analysing…') : mtxt('applying', 'Applying changes…');
            phaseEl.textContent = base + (job.progress != null ? ' ' + job.progress + '%' : '');
        }
    }
    function gjobPoll() {
        if (GJOB.inFlight) { return; }
        var statusUrl = endpoint('updateJobStatus') || endpoint('jobStatus');
        var logUrl = endpoint('updateJobLog');
        if (!statusUrl) { GJOB.active = false; return; }
        GJOB.inFlight = true;
        postJson(statusUrl).then(function (d) {
            var job = d && d.job ? d.job : null;
            gjobRenderJob(job);
            var chain = Promise.resolve();
            if (logUrl) {
                chain = fetch(logUrl + (logUrl.indexOf('?') === -1 ? '?' : '&') + 'offset=' + GJOB.offset, {
                    method: 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: '{}'
                }).then(function (r) { return r.json(); }).then(function (l) {
                    if (l && l.success) { gjobAppendLog(l.entries); if (typeof l.offset === 'number') { GJOB.offset = l.offset; } }
                }).catch(function () {});
            }
            chain.then(function () {
                if (job && (job.status === 'running' || job.status === 'queued')) {
                    setTimeout(gjobPoll, 2000);
                } else {
                    GJOB.active = false;
                    gjobFinalize(job);
                }
            });
        }).catch(function () { GJOB.active = false; if (GJOB.prefix) { gjobMsg(GJOB.prefix, t('js.error'), 'error'); } })
          .finally(function () { GJOB.inFlight = false; });
    }
    // On completion the finished job is ARCHIVED and the active slot is cleared,
    // so the last status poll can return null. Fetch the archived record by id to
    // get the authoritative final status + structured failure detail before the
    // per-extension finish handler renders.
    function gjobFinalize(job) {
        var cb = GJOB.onFinished;
        var jobId = (GJOB.ctx && GJOB.ctx.jobId) || (job && job.id) || null;
        var detailsUrl = endpoint('updateJobDetails');
        if (jobId && detailsUrl && (!job || job.status !== 'running')) {
            var u = detailsUrl + (detailsUrl.indexOf('?') === -1 ? '?' : '&') + 'id=' + encodeURIComponent(jobId);
            postJson(u).then(function (d) {
                var finalJob = d && d.job ? d.job : job;
                // Render the AUTHORITATIVE persisted job before the finish handler
                // runs, so every completed step shows complete instead of freezing
                // at wherever the last incremental poll observed the job.
                gjobRenderJob(finalJob);
                if (cb) { cb(finalJob); }
            }).catch(function () { gjobRenderJob(job); if (cb) { cb(job); } });
            return;
        }
        gjobRenderJob(job);
        if (cb) { cb(job); }
    }
    // Rich, localized failure markup from a job's structured result.
    function jobFailureHtml(job) {
        job = job || {};
        var parts = [];
        var code = job.errorCode || null;
        var summary = code ? xtxt('fail.summary.' + code, '') : '';
        if (!summary) { summary = job.error && !code ? job.error : mtxt('failed'); }
        parts.push('<div class="updater-inline-err"><strong>' + esc(summary) + '</strong></div>');
        var details = (job.details || []).filter(function (d) { return d; });
        if (details.length) {
            parts.push('<div class="updater-pkg-meta"><strong>' + esc(xtxt('fail.detailsHeading', 'Details')) + ':</strong><ul style="margin:.3rem 0 0 1rem;">'
                + details.map(function (d) { return '<li><code>' + esc(d) + '</code></li>'; }).join('') + '</ul></div>');
        }
        var recs = (job.recommendations || []).filter(function (r) { return r; });
        if (recs.length) {
            parts.push('<div class="updater-pkg-meta"><strong>' + esc(xtxt('fail.recHeading', 'Recommendations')) + ':</strong><ul style="margin:.3rem 0 0 1rem;">'
                + recs.map(function (r) { return '<li>' + esc(xtxt('fail.rec.' + r, r)) + '</li>'; }).join('') + '</ul></div>');
        }
        if (job.composerExitCode != null) {
            parts.push('<div class="updater-pkg-meta">' + esc(xtxt('fail.exitCode', 'Composer exit code')) + ': <strong>' + esc(job.composerExitCode) + '</strong></div>');
        }
        if (job.rollback_result) { parts.push('<div class="updater-inline-msg">' + esc(mtxt('rolledBack')) + '</div>'); }
        return parts.join('');
    }
    function gjobError(d) { if (d && d.reason) { return mreason(d.reason); } return (d && d.error) || t('js.error'); }

    /* — TER search — */
    var TER = { seq: 0, results: [], sticky: {} };
    function terStatusPrefix(key) { return 'ter-status-' + cssId(key); }
    function captureTerSticky(key) { var el = byId(terStatusPrefix(key)); if (el) { TER.sticky[key] = el.innerHTML; } }
    function clearTerSticky(key) { delete TER.sticky[key]; }
    function compatBadge(state) {
        var cls = state === 'installable' ? 'updater-status-success' : (state === 'metadata_unavailable' ? 'updater-status-idle' : 'updater-status-error');
        return '<span class="updater-status-badge ' + cls + '">' + esc(mreason(state)) + '</span>';
    }

    function terSearch() {
        var input = byId('guardianTerQuery');
        var box = byId('guardianTerResults');
        if (!input || !box) { return; }
        var query = (input.value || '').trim();
        if (query.length < 2) { box.innerHTML = '<div class="updater-empty">' + esc(xtxt('queryTooShort')) + '</div>'; return; }
        var url = endpoint('terSearch'); if (!url) { return; }
        var seq = ++TER.seq;
        box.innerHTML = '<div style="color:var(--updater-text-soft);padding:.8rem;">' + esc(xtxt('searching')) + '</div>';
        postJson(url, { query: query }).then(function (d) {
            if (seq !== TER.seq) { return; } // a newer search already superseded this one
            if (!d || d.success !== true) { box.innerHTML = '<div class="updater-result-summary error">❌ ' + esc(gjobError(d)) + '</div>'; return; }
            TER.results = d.results || [];
            renderTerResults(box);
        }).catch(function () { if (seq === TER.seq) { box.innerHTML = '<div class="updater-result-summary error">❌ ' + esc(t('js.error')) + '</div>'; } });
    }

    function renderTerResults(box) {
        if (!TER.results.length) { box.innerHTML = '<div class="updater-empty">' + esc(xtxt('noResultsTer', 'No matching TER extension was found.')) + '</div>'; return; }
        box.innerHTML = TER.results.map(function (e) {
            var badges = [];
            if (e.already_installed) { badges.push('<span class="updater-status-badge updater-status-ok">' + esc(xtxt('installedBadge')) + '</span>'); }
            if (e.abandoned) { badges.push('<span class="updater-pill updater-pill-core">' + esc(xtxt('abandoned')) + '</span>'); }
            if (e.deprecated) { badges.push('<span class="updater-pill updater-pill-core">' + esc(xtxt('deprecated')) + '</span>'); }
            // Composer identity and compatibility are shown as INDEPENDENT badges;
            // one failing dimension never hides the other, and neither produces a
            // global error — problems stay inside this card.
            badges.push(compatBadge(e.compatibility_state));
            var action = e.auto_installable
                ? '<button type="button" class="updater-btn updater-btn-sm" data-action="ter-install" data-key="' + esc(e.extension_key) + '">⬇️ ' + esc(xtxt('install')) + '</button>'
                : '<button type="button" class="updater-btn updater-btn-sm" disabled title="' + esc(mreason(e.reason)) + '">⬇️ ' + esc(xtxt('install')) + '</button>';
            var identity = e.composer_available
                ? esc(xtxt('composerName')) + ': <strong>' + esc(e.composer_name) + '</strong>'
                : '<span class="updater-inline-err">' + esc(mreason('composer_identity_missing')) + '</span>';
            return '<div class="updater-card" style="margin:.5rem 0;padding:.8rem 1rem;">'
                + '<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">'
                + '<div><strong>' + esc(e.name || e.extension_key) + '</strong> <span class="updater-pkg-version">' + esc(e.extension_key) + '</span> ' + badges.join(' ') + '</div>'
                + '<div>' + action + '</div></div>'
                + '<div style="font-size:.82rem;color:var(--updater-text-muted);margin-top:.3rem;">' + esc(e.description || '') + '</div>'
                + '<div class="updater-pkg-meta">' + esc(xtxt('latest')) + ': <strong>' + esc(e.latest_version || '?') + '</strong>'
                + ' · ' + identity
                + ' · TYPO3: <strong>' + compatText(e.typo3_compatible) + '</strong>'
                + ' · PHP: <strong>' + compatText(e.php_compatible) + '</strong>'
                + ' · ' + esc(xtxt('author')) + ': <strong>' + esc(e.author || '—') + '</strong>'
                + (e.last_updated ? ' · ' + esc(xtxt('updated')) + ': <strong>' + esc(String(e.last_updated).slice(0, 10)) + '</strong>' : '')
                + '</div>'
                + '<div id="' + terStatusPrefix(e.extension_key) + '" class="updater-ter-status"></div>'
                + '</div>';
        }).join('');
        // Re-attach any in-flight/completed per-card status so a new search that
        // still lists the same extension does not erase its panel.
        Object.keys(TER.sticky).forEach(function (key) {
            var el = byId(terStatusPrefix(key));
            if (el) { el.innerHTML = TER.sticky[key]; }
        });
    }

    function terInstall(el) {
        var key = el.dataset.key;
        if (!key || GJOB.active) { return; }
        var url = endpoint('terInstallDryRun'); if (!url) { return; }
        var prefix = terStatusPrefix(key);
        if (!byId(prefix)) { return; }
        var op = ++OPSEQ;
        clearTerSticky(key);
        gjobRenderPanel(prefix, key, xtxt('analyzing'));
        scrollIntoViewIfNeeded(byId(prefix));
        postJson(url, { extensionKey: key }).then(function (d) {
            if (op !== OPSEQ) { return; }
            if (!d || d.success !== true) { gjobMsg(prefix, gjobError(d), 'error'); captureTerSticky(key); return; }
            gjobStart(prefix, 'dry', { key: key, prefix: prefix, scope: 'ter', jobId: d.jobId }, terJobFinished);
        }).catch(function () { if (op === OPSEQ) { gjobMsg(prefix, t('js.error'), 'error'); captureTerSticky(key); } });
    }
    function terConfirm() {
        var ctx = GJOB.ctx;
        if (!ctx || ctx.scope !== 'ter' || GJOB.active) { return; }
        var key = ctx.key; var prefix = ctx.prefix;
        var url = endpoint('terInstallStart'); if (!url) { return; }
        var op = ++OPSEQ;
        var snap = (byId(prefix + 'Snapshot') || {}).checked;
        gjobRenderPanel(prefix, key, mtxt('applying', 'Applying changes…'));
        postJson(url, { extensionKey: key, confirm: true, snapshotVendor: snap !== false }).then(function (d) {
            if (op !== OPSEQ) { return; }
            if (!d || d.success !== true) { gjobMsg(prefix, gjobError(d), 'error'); captureTerSticky(key); return; }
            gjobStart(prefix, 'live', { key: key, prefix: prefix, scope: 'ter', jobId: d.jobId }, terJobFinished);
        }).catch(function () { if (op === OPSEQ) { gjobMsg(prefix, t('js.error'), 'error'); captureTerSticky(key); } });
    }
    function terJobFinished(job) {
        var ctx = GJOB.ctx || {}; var prefix = ctx.prefix; var key = ctx.key;
        var actions = byId(prefix + 'Actions');
        var phaseEl = byId(prefix + 'Phase');
        if (!job) { gjobMsg(prefix, t('js.error'), 'error'); captureTerSticky(key); return; }
        if (job.status !== 'succeeded') {
            if (phaseEl) { phaseEl.textContent = job.result_status === 'blocked' ? xtxt('fail.blocked', 'Blocked') : mtxt('failed', 'Failed'); }
            if (actions) { actions.innerHTML = jobFailureHtml(job); }
            captureTerSticky(key);
            return;
        }
        if (GJOB.phase === 'dry') {
            if (phaseEl) { phaseEl.textContent = mtxt('dryOk', 'Dry run passed.'); }
            if (actions) {
                actions.innerHTML = '<label class="updater-pkg-checkbox" style="display:block;margin-bottom:.4rem;"><input type="checkbox" id="' + prefix + 'Snapshot" checked> ' + esc(mtxt('snapshotVendor')) + '</label>'
                    + '<button type="button" class="updater-btn" data-action="ter-install-confirm">✅ ' + esc(xtxt('confirmInstall')) + '</button> '
                    + '<button type="button" class="updater-btn updater-btn-secondary" data-action="ter-cancel" data-key="' + esc(key) + '">✖ ' + esc(mtxt('cancel')) + '</button>';
            }
            captureTerSticky(key);
            return;
        }
        if (phaseEl) { phaseEl.textContent = mtxt('done', 'Completed successfully.'); }
        if (actions) { actions.innerHTML = '<div class="updater-inline-ok">✅ ' + esc(mtxt('done')) + '</div>'; }
        captureTerSticky(key);
        // Refresh the installed list (a different container); the TER card panel
        // stays attached to its result and is not cleared.
        if (typeof loadDashboardPackages === 'function') { loadDashboardPackages(); }
    }
    function terCancel(el) {
        var key = el && el.dataset ? el.dataset.key : null;
        if (key) { clearTerSticky(key); var el2 = byId(terStatusPrefix(key)); if (el2) { el2.innerHTML = ''; } }
    }

    /* — custom ZIP upload — */
    var UPLOAD = { token: null, checksum: null, fingerprint: null };

    // Structured, safe upload-failure markup: localized summary + safe details
    // (the private relative runtime area, never an absolute path) + recommendation.
    function uploadFailureHtml(d) {
        d = d || {};
        var code = d.errorCode || d.reason || 'upload_processing_error';
        var parts = ['<div class="updater-inline-err"><strong>' + esc(mreason(code)) + '</strong></div>'];
        var details = [];
        if (d.area) { details.push(xtxt('upload.detailArea', 'Required writable runtime area') + ': ' + d.area); }
        if (d.detail) { details.push(d.detail); }
        if (details.length) {
            parts.push('<div class="updater-pkg-meta">' + details.map(function (x) { return esc(x); }).join('<br>') + '</div>');
        }
        var rec = xtxt('upload.rec.' + code, '');
        if (rec && rec !== 'upload.rec.' + code) {
            parts.push('<div class="updater-pkg-meta"><strong>' + esc(xtxt('fail.recHeading', 'Recommendations')) + ':</strong> ' + esc(rec) + '</div>');
        }
        return parts.join('');
    }

    function uploadSelect() {
        var file = byId('guardianUploadFile');
        var btn = byId('guardianUploadBtn');
        if (btn) { btn.disabled = !(file && file.files && file.files.length); }
    }
    function uploadStart() {
        var file = byId('guardianUploadFile');
        var msg = byId('guardianUploadMsg');
        var panel = byId('guardianUploadPanel');
        if (!file || !file.files || !file.files.length) { if (msg) { msg.textContent = xtxt('selectFirst'); } return; }
        var url = endpoint('uploadExtension'); if (!url || !panel) { return; }
        var fd = new FormData();
        fd.append('extensionArchive', file.files[0]);
        if (msg) { msg.textContent = xtxt('uploading'); msg.className = 'updater-inline-msg'; }
        panel.innerHTML = '';
        // The endpoint URL already carries the CSRF route token; send multipart.
        fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || d.success !== true) {
                    if (msg) { msg.textContent = ''; }
                    // Structured, safe failure: summary + safe details + recommendation.
                    var box = byId('guardianUploadPanel');
                    if (box) { box.innerHTML = '<div class="updater-result-summary error">' + uploadFailureHtml(d) + '</div>'; }
                    return;
                }
                UPLOAD.token = d.token; UPLOAD.checksum = d.checksum;
                if (msg) { msg.textContent = ''; }
                uploadInspect();
            }).catch(function () { if (msg) { msg.textContent = ''; } gjobMsg('guardianUploadPanel', t('js.error'), 'error'); });
    }
    function uploadInspect() {
        var url = endpoint('uploadInspect'); if (!url || !UPLOAD.token) { return; }
        gjobMsg('guardianUploadPanel', xtxt('inspecting'), 'idle');
        postJson(url, { token: UPLOAD.token }).then(function (d) {
            if (!d || d.success !== true) { gjobMsg('guardianUploadPanel', gjobError(d), 'error'); return; }
            UPLOAD.fingerprint = d.fingerprint;
            UPLOAD.extensionKey = (d.inspection && d.inspection.extension_key) || null;
            renderInspection(d.inspection || {}, d.installable === true, d.existingDirectory || null);
        }).catch(function () { gjobMsg('guardianUploadPanel', t('js.error'), 'error'); });
    }
    function renderInspection(ins, installable, existing) {
        var panel = byId('guardianUploadPanel');
        if (!panel) { return; }
        var reasons = (ins.reasons || []).map(function (r) { return '<li>' + esc(mreason(r)) + '</li>'; }).join('');
        var rows = ''
            + '<div class="updater-pkg-meta">' + esc(xtxt('identity')) + ': <strong>' + esc(ins.composer_name || ins.extension_key || '—') + '</strong>'
            + ' · ' + esc(xtxt('latest')) + ': <strong>' + esc(ins.version || '—') + '</strong>'
            + ' · TYPO3: <strong>' + esc(ins.typo3_constraint || '—') + '</strong>'
            + ' · PHP: <strong>' + esc(ins.php_constraint || '—') + '</strong></div>';
        var html = '<div class="updater-card" style="padding:.8rem 1rem;"><strong>' + esc(ins.extension_key || '—') + '</strong>' + (ins.legacy ? ' <span class="updater-pill updater-pill-custom">legacy</span>' : '') + rows;
        if (reasons) { html += '<div class="updater-inline-err" style="margin-top:.5rem;"><strong>' + esc(xtxt('blocking')) + ':</strong><ul style="margin:.3rem 0 0 1rem;">' + reasons + '</ul></div>'; }
        html += existingDirectoryHtml(existing, ins.extension_key || '');
        html += '<div style="margin-top:.6rem;">';
        if (installable) {
            html += '<button type="button" class="updater-btn" data-action="custom-dry-run">🧪 ' + esc(xtxt('dryRun')) + '</button> ';
        } else {
            html += '<span class="updater-inline-err">' + esc(xtxt('notInstallable')) + '</span> ';
        }
        html += '<button type="button" class="updater-btn updater-btn-secondary" data-action="ext-cancel">✖ ' + esc(mtxt('cancel')) + '</button></div></div>';
        html += '<div id="guardianCustomJob" style="margin-top:.6rem;"></div>';
        panel.innerHTML = html;
    }
    // Render the classification of an existing packages/<key> directory found
    // while the package is not installed (an orphan from a prior removal).
    function existingDirectoryHtml(existing, extensionKey) {
        if (!existing || !existing.classification || existing.classification === 'none') { return ''; }
        var cls = existing.classification;
        var identity = existing.detected_name
            ? '<div class="updater-pkg-meta">' + esc(xtxt('detectedPackage')) + ': <code>' + esc(existing.detected_name) + (existing.detected_version ? ' ' + existing.detected_version : '') + '</code> · <code>' + esc(existing.source_relative || '') + '</code></div>'
            : '';
        if (cls === 'verified_guardian_orphan') {
            return '<div class="updater-inline-msg" style="margin-top:.5rem;"><strong>' + esc(xtxt('orphanManagedDirectory')) + '</strong> · ' + esc(xtxt('orphanOwnedHint')) + identity
                + '<div style="margin-top:.4rem;"><button type="button" class="updater-btn updater-btn-secondary" data-action="orphan-reuse" data-key="' + esc(extensionKey) + '">♻️ ' + esc(xtxt('reuseExistingDirectory')) + '</button> '
                + '<button type="button" class="updater-btn updater-btn-secondary" data-action="orphan-remove" data-key="' + esc(extensionKey) + '">🗑️ ' + esc(xtxt('removeOrphanedDirectory')) + '</button></div></div>';
        }
        var hint = cls === 'conflicting' ? xtxt('orphanConflictHint') : xtxt('orphanUnownedHint');
        return '<div class="updater-inline-err" style="margin-top:.5rem;"><strong>' + esc(xtxt('directoryConflict')) + '</strong> · ' + esc(hint) + identity + '</div>';
    }
    function orphanReuse(el) {
        // Reuse the Guardian-owned directory in place: proceed straight to dry run.
        // applyForInstall reclaims the owned directory and installs the exact ZIP.
        if (el && el.dataset && el.dataset.key) { UPLOAD.extensionKey = el.dataset.key; }
        customDryRun();
    }
    function orphanRemove(el) {
        var key = el && el.dataset ? el.dataset.key : (UPLOAD.extensionKey || '');
        var url = endpoint('customOrphanRemove'); if (!url || !key) { return; }
        gjobMsg('guardianUploadPanel', xtxt('removingOrphan'), 'idle');
        postJson(url, { extensionKey: key }).then(function (d) {
            if (!d || d.success !== true) { gjobMsg('guardianUploadPanel', gjobError(d), 'error'); return; }
            gjobMsg('guardianUploadPanel', xtxt('orphanRemoved'), 'ok');
            uploadInspect();
        }).catch(function () { gjobMsg('guardianUploadPanel', t('js.error'), 'error'); });
    }
    function customDryRun() {
        if (!UPLOAD.token || !UPLOAD.fingerprint || GJOB.active) { return; }
        var url = endpoint('customDryRun'); if (!url) { return; }
        gjobRenderPanel('guardianCustomJob', UPLOAD.token, xtxt('analyzing'));
        postJson(url, { token: UPLOAD.token, fingerprint: UPLOAD.fingerprint }).then(function (d) {
            if (!d || d.success !== true) { gjobMsg('guardianCustomJob', gjobError(d), 'error'); return; }
            gjobStart('guardianCustomJob', 'dry', { jobId: d.jobId }, customJobFinished);
        }).catch(function () { gjobMsg('guardianCustomJob', t('js.error'), 'error'); });
    }
    function customConfirm() {
        if (!UPLOAD.token || !UPLOAD.fingerprint || GJOB.active) { return; }
        var url = endpoint('customInstallStart'); if (!url) { return; }
        var snap = (byId('guardianCustomJobSnapshot') || {}).checked;
        gjobRenderPanel('guardianCustomJob', UPLOAD.token, mtxt('applying', 'Applying changes…'));
        postJson(url, { token: UPLOAD.token, fingerprint: UPLOAD.fingerprint, confirm: true, snapshotVendor: snap !== false }).then(function (d) {
            if (!d || d.success !== true) { gjobMsg('guardianCustomJob', gjobError(d), 'error'); return; }
            gjobStart('guardianCustomJob', 'live', { jobId: d.jobId }, customJobFinished);
        }).catch(function () { gjobMsg('guardianCustomJob', t('js.error'), 'error'); });
    }
    function customJobFinished(job) {
        var actions = byId('guardianCustomJobActions');
        var phaseEl = byId('guardianCustomJobPhase');
        if (!job) { gjobMsg('guardianCustomJob', t('js.error'), 'error'); return; }
        if (job.status !== 'succeeded') {
            if (phaseEl) { phaseEl.textContent = job.result_status === 'blocked' ? xtxt('fail.blocked', 'Blocked') : mtxt('failed', 'Failed'); }
            // Full, actionable report (summary + Composer detail lines +
            // recommendations + exit code) — never a bare "Error".
            if (actions) { actions.innerHTML = jobFailureHtml(job); }
            return;
        }
        if (GJOB.phase === 'dry') {
            if (phaseEl) { phaseEl.textContent = mtxt('dryOk', 'Dry run passed.'); }
            if (actions) {
                actions.innerHTML = '<label class="updater-pkg-checkbox" style="display:block;margin-bottom:.4rem;"><input type="checkbox" id="guardianCustomJobSnapshot" checked> ' + esc(mtxt('snapshotVendor')) + '</label>'
                    + '<button type="button" class="updater-btn" data-action="custom-confirm">✅ ' + esc(xtxt('installStaged')) + '</button> '
                    + '<button type="button" class="updater-btn updater-btn-secondary" data-action="ext-cancel">✖ ' + esc(mtxt('cancel')) + '</button>';
            }
            return;
        }
        if (phaseEl) { phaseEl.textContent = mtxt('done', 'Completed successfully.'); }
        if (actions) { actions.innerHTML = '<div class="updater-inline-ok">✅ ' + esc(mtxt('done')) + '</div>'; }
        cleanupUpload();
        if (typeof loadDashboardPackages === 'function') { loadDashboardPackages(); }
    }
    function cleanupUpload() {
        var url = endpoint('uploadCleanup');
        if (url && UPLOAD.token) { postJson(url, { token: UPLOAD.token }).catch(function () {}); }
    }
    function extCancel() {
        if (GJOB.active) { return; }
        var cj = byId('guardianCustomJob'); if (cj) { cj.innerHTML = ''; }
    }

    /* — Guardian self-maintenance (deferred disable + controlled uninstall) —
     * Runs from Guardian's OWN row status panel (same UI as every other row);
     * only the safer deferred workflow + typed confirmations differ. */
    var GUARDIANX = { redirect: null, prefix: null, pkg: null };

    function guardianPkg(el) { return (el && el.dataset && el.dataset.package) || 'vtinnovations/guardian-typo3'; }

    function guardianSelfDisable(el) {
        var phrase = window.prompt(xtxt('guardianDisablePrompt'));
        if (phrase == null) { return; }
        if (phrase.trim() !== 'DISABLE GUARDIAN') { window.alert(xtxt('disablePhraseHint')); return; }
        var url = endpoint('guardianSelfDisable'); if (!url) { return; }
        var pkg = guardianPkg(el); var prefix = pkgStatusPrefix(pkg);
        GUARDIANX.prefix = prefix; GUARDIANX.pkg = pkg;
        openRowStatus(pkg); scrollIntoViewIfNeeded(byId(prefix));
        clearSticky(pkg);
        gjobMsg(prefix, xtxt('guardianDisableQueued'), 'idle');
        postJson(url, { confirmPhrase: phrase }).then(function (d) {
            if (!d || d.success !== true) { gjobMsg(prefix, managePkgError(d), 'error'); captureSticky(pkg); return; }
            GUARDIANX.redirect = d.redirect || '/typo3/';
            pollSelfStatus();
        }).catch(function () { gjobMsg(prefix, t('js.error'), 'error'); captureSticky(pkg); });
    }
    function pollSelfStatus() {
        var url = endpoint('guardianSelfStatus'); var prefix = GUARDIANX.prefix; if (!url || !prefix) { return; }
        postJson(url).then(function (d) {
            var s = d && d.status ? d.status.status : null;
            if (s === 'succeeded') {
                gjobMsg(prefix, xtxt('guardianDisabled'), 'ok');
                setTimeout(function () { window.location.href = GUARDIANX.redirect || '/typo3/'; }, 1400);
                return;
            }
            if (s === 'failed') {
                gjobMsg(prefix, (d.status && d.status.message) || mtxt('failed'), 'error');
                return;
            }
            setTimeout(pollSelfStatus, 1500);
        }).catch(function () { setTimeout(pollSelfStatus, 3000); });
    }

    function guardianUninstallStart(el) {
        if (GJOB.active) { return; }
        var url = endpoint('guardianUninstallDryRun'); if (!url) { return; }
        var pkg = guardianPkg(el); var prefix = pkgStatusPrefix(pkg);
        var panel = openRowStatus(pkg); if (!panel) { return; }
        GUARDIANX.prefix = prefix; GUARDIANX.pkg = pkg;
        clearSticky(pkg);
        gjobRenderPanel(prefix, mtxt('action.remove') + ' · ' + pkg, xtxt('analyzing'));
        scrollIntoViewIfNeeded(byId(prefix));
        postJson(url, {}).then(function (d) {
            if (!d || d.success !== true) { gjobMsg(prefix, managePkgError(d), 'error'); captureSticky(pkg); return; }
            gjobStart(prefix, 'dry', { kind: 'guardian', prefix: prefix, pkg: pkg, jobId: d.jobId }, guardianJobFinished);
        }).catch(function () { gjobMsg(prefix, t('js.error'), 'error'); captureSticky(pkg); });
    }
    function guardianUninstallConfirm() {
        var ctx = GJOB.ctx || {}; var prefix = ctx.prefix || GUARDIANX.prefix; var pkg = ctx.pkg || GUARDIANX.pkg;
        var phrase = ((byId(prefix + 'RemovePhrase') || {}).value || '').trim();
        if (phrase !== 'REMOVE GUARDIAN') { window.alert(xtxt('removePhraseHint')); return; }
        if (GJOB.active) { return; }
        var url = endpoint('guardianUninstall'); if (!url) { return; }
        var snap = (byId(prefix + 'RemoveSnapshot') || {}).checked;
        gjobRenderPanel(prefix, mtxt('action.remove') + ' · ' + pkg, xtxt('removingGuardian'));
        postJson(url, { confirmPhrase: phrase, snapshotVendor: snap !== false }).then(function (d) {
            if (!d || d.success !== true) { gjobMsg(prefix, managePkgError(d), 'error'); captureSticky(pkg); return; }
            GUARDIANX.redirect = d.redirect || '/typo3/';
            gjobStart(prefix, 'live', { kind: 'guardian', prefix: prefix, pkg: pkg, jobId: d.jobId }, guardianJobFinished);
        }).catch(function () { gjobMsg(prefix, t('js.error'), 'error'); captureSticky(pkg); });
    }
    function guardianJobFinished(job) {
        var ctx = GJOB.ctx || {}; var prefix = ctx.prefix; var pkg = ctx.pkg;
        var actions = byId(prefix + 'Actions');
        var phaseEl = byId(prefix + 'Phase');
        if (!job) { gjobMsg(prefix, t('js.error'), 'error'); captureSticky(pkg); return; }
        if (job.status !== 'succeeded') {
            if (phaseEl) { phaseEl.textContent = job.result_status === 'blocked' ? xtxt('fail.blocked', 'Blocked') : mtxt('failed'); }
            if (actions) { actions.innerHTML = jobFailureHtml(job); }
            captureSticky(pkg);
            return;
        }
        if (GJOB.phase === 'dry') {
            if (phaseEl) { phaseEl.textContent = mtxt('dryOk'); }
            if (actions) {
                actions.innerHTML = '<div class="updater-inline-err" style="margin-bottom:.5rem;">⚠️ ' + esc(xtxt('guardianRemoveWarning')) + '</div>'
                    + '<label class="updater-pkg-checkbox" style="display:block;margin-bottom:.4rem;"><input type="checkbox" id="' + prefix + 'RemoveSnapshot" checked> ' + esc(mtxt('snapshotVendor')) + '</label>'
                    + '<input type="text" id="' + prefix + 'RemovePhrase" class="updater-pkg-filter" placeholder="' + esc(xtxt('typeRemoveGuardian')) + '" style="margin-bottom:.4rem;"> '
                    + '<button type="button" class="updater-btn updater-btn-danger" data-action="guardian-uninstall-confirm">🗑️ ' + esc(mtxt('action.remove')) + '</button> '
                    + '<button type="button" class="updater-btn updater-btn-secondary" data-action="manage-cancel">✖ ' + esc(mtxt('cancel')) + '</button>';
            }
            captureSticky(pkg);
            return;
        }
        // live removal succeeded → Guardian is gone; redirect to a safe page.
        if (phaseEl) { phaseEl.textContent = mtxt('done'); }
        setTimeout(function () { window.location.href = GUARDIANX.redirect || '/typo3/'; }, 1400);
    }

    /* ── Schedule (read-only populate) ───────────────────────────── */

    function toggleScheduleRows(prefix) {
        var freqEl = byId('sched' + prefix + 'Frequency');
        if (!freqEl) { return; }
        var freq = freqEl.value;
        var isInterval = (freq === '5min' || freq === '15min' || freq === 'hourly');
        var timeInput = byId('sched' + prefix + 'Time');
        if (timeInput && timeInput.closest('.updater-sched-row')) {
            timeInput.closest('.updater-sched-row').style.display = isInterval ? 'none' : '';
        }
        var wk = byId('sched' + prefix + 'WeekdayRow');
        var dm = byId('sched' + prefix + 'DomRow');
        if (wk) { wk.style.display = (freq === 'weekly') ? '' : 'none'; }
        if (dm) { dm.style.display = (freq === 'monthly') ? '' : 'none'; }
    }

    function setVal(id, value) { var el = byId(id); if (el) { el.value = value; } }
    function setChecked(id, value) { var el = byId(id); if (el) { el.checked = !!value; } }

    function loadSchedule() {
        var url = endpoint('scheduleGet'); if (!url) { return; }
        postJson(url).then(function (data) {
            if (!data.success || !data.config) { return; }
            var c = data.config;
            ['mini', 'full'].forEach(function (t) {
                var s = c[t] || {};
                var P = t === 'mini' ? 'Mini' : 'Full';
                setChecked('sched' + P + 'Enabled', s.enabled);
                setVal('sched' + P + 'Frequency', s.frequency || 'daily');
                setVal('sched' + P + 'Time', s.time || '03:00');
                setVal('sched' + P + 'Weekday', String(s.weekday != null ? s.weekday : 1));
                setVal('sched' + P + 'Dom', String(s.day_of_month != null ? s.day_of_month : 1));
                setVal('sched' + P + 'Retention', String(s.retention != null ? s.retention : 7));
                toggleScheduleRows(P);
            });
            var full = c.full || {};
            var comp = full.components || {};
            setChecked('schedFullCompVendor', comp.vendor);
            setChecked('schedFullCompTemplates', comp.configuration);
            setChecked('schedFullCompFiles', comp.fileadmin);
            setChecked('schedFullCompAssets', comp.publicAssets);
            var n = c.notifications || {};
            setVal('schedStoragePath', c.storage_path || '');
            setVal('schedEmail', n.email || '');
            setVal('schedSenderEmail', n.sender_email || '');
            setVal('schedSenderName', n.sender_name || 'Guardian');
            setChecked('schedNotifySuccess', n.on_success);
            setChecked('schedNotifyFailure', n.on_failure);
        }).catch(function () {});
    }

    /* ── Settings: recovery e-mail notifications + PHP CLI binary ──────────
     *
     * The two sections are fully independent: a failure in one never breaks the
     * other (each writes only to its own message area and traps its own errors).
     * Values are (re)loaded from the server after every successful save so what
     * is shown always equals what is persisted. */

    function loadRuntime() {
        var url = endpoint('runtimeGet'); if (!url) { return; }
        postJson(url).then(function (data) {
            if (!data.success || !data.config) { return; }
            var c = data.config;
            setVal('runtimePhpBinary', c.php_binary || '');
            setVal('runtimeRecoveryPanelFilename', c.recovery_panel_filename || '');
            setVal('recoveryEmailInput', c.recovery_email || '');
            setVal('recoverySenderInput', c.notification_sender_email || '');
            setChecked('recoveryNotifyEnabled', c.recovery_notifications_enabled === true);
        }).catch(function () {});
    }

    function stxt(code, fallback) { var tr = t('js.settings.' + code); return tr !== 'js.settings.' + code ? tr : (fallback || code); }
    function settingsMsg(id, text, ok) {
        var el = byId(id);
        if (!el) { return; }
        el.textContent = text || '';
        el.className = 'updater-inline-msg ' + (ok === true ? 'updater-inline-ok' : (ok === false ? 'updater-inline-err' : ''));
    }
    function settingsError(d) {
        if (d && d.reason) { var tr = t('js.settings.err.' + d.reason); if (tr !== 'js.settings.err.' + d.reason) { return tr; } return d.reason; }
        return (d && d.error) || t('js.error');
    }
    function busy(el, on) { if (!el) { return false; } if (on) { if (el.dataset.busy === '1') { return true; } el.dataset.busy = '1'; el.disabled = true; return false; } el.dataset.busy = ''; el.disabled = false; return false; }

    function notificationsPayload() {
        return {
            enabled: (byId('recoveryNotifyEnabled') || {}).checked === true,
            recipients: (byId('recoveryEmailInput') || {}).value || '',
            sender: (byId('recoverySenderInput') || {}).value || ''
        };
    }
    function saveNotifications(el) {
        var url = endpoint('notificationsSave'); if (!url || busy(el, true)) { return; }
        settingsMsg('recoveryEmailMsg', stxt('saving', 'Saving…'), null);
        postJson(url, notificationsPayload()).then(function (d) {
            if (d && d.success === true) { settingsMsg('recoveryEmailMsg', stxt('saved', 'Settings saved.'), true); loadRuntime(); }
            else { settingsMsg('recoveryEmailMsg', settingsError(d), false); }
        }).catch(function () { settingsMsg('recoveryEmailMsg', t('js.error'), false); })
          .finally(function () { busy(el, false); });
    }
    function testNotifications(el) {
        var url = endpoint('notificationsTest'); if (!url || busy(el, true)) { return; }
        settingsMsg('recoveryEmailMsg', stxt('sending', 'Sending test…'), null);
        postJson(url, notificationsPayload()).then(function (d) {
            if (d && d.success === true) { settingsMsg('recoveryEmailMsg', stxt('sent', 'Test notification sent to') + ' ' + (d.recipient || ''), true); }
            else { settingsMsg('recoveryEmailMsg', settingsError(d), false); }
        }).catch(function () { settingsMsg('recoveryEmailMsg', t('js.error'), false); })
          .finally(function () { busy(el, false); });
    }

    function phpReason(code) { if (!code) { return stxt('php.unknown', 'Unknown'); } var tr = t('js.settings.php.' + code); return tr !== 'js.settings.php.' + code ? tr : code; }
    function phpDetect(el) {
        var url = endpoint('phpDetect'); if (!url || busy(el, true)) { return; }
        var box = byId('runtimeCandidates'); if (box) { box.innerHTML = esc(stxt('detecting', 'Detecting…')); }
        postJson(url).then(function (d) {
            if (d && d.success === true) { renderPhpCandidates(d); }
            else { if (box) { box.innerHTML = ''; } settingsMsg('phpMsg', settingsError(d), false); }
        }).catch(function () { settingsMsg('phpMsg', t('js.error'), false); })
          .finally(function () { busy(el, false); });
    }
    function renderPhpCandidates(d) {
        var box = byId('runtimeCandidates'); if (!box) { return; }
        var list = d.candidates || [];
        if (!list.length) { box.innerHTML = '<span class="updater-inline-err">' + esc(stxt('noCandidates', 'No PHP CLI binaries were detected.')) + '</span>'; return; }
        box.innerHTML = list.map(function (r) {
            var info = r.version ? ('PHP ' + r.version) : phpReason(r.error_code);
            var mark = r.valid ? '✓' : '✕';
            var note = (r.version && r.satisfies_typo3 === false) ? ' · ' + stxt('belowTypo3Min', 'below TYPO3 minimum') : '';
            return '<div style="margin:.15rem 0;display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;">'
                + '<button type="button" class="updater-btn updater-btn-sm updater-btn-secondary" data-action="php-pick" data-path="' + esc(r.real_path || r.path) + '">' + esc(r.real_path || r.path) + '</button>'
                + '<span>' + mark + ' ' + esc(info + note) + '</span></div>';
        }).join('');
    }
    function phpPick(el) { setVal('runtimePhpBinary', el.dataset.path || ''); phpTest(byId('phpTestBtn')); }
    function phpTest(el) {
        var url = endpoint('phpTest'); if (!url || busy(el, true)) { return; }
        var path = (byId('runtimePhpBinary') || {}).value || '';
        settingsMsg('phpMsg', stxt('testing', 'Testing…'), null);
        postJson(url, { path: path }).then(function (d) {
            if (d && d.success === true) { renderPhpResult(d.result); }
            else { settingsMsg('phpMsg', settingsError(d), false); }
        }).catch(function () { settingsMsg('phpMsg', t('js.error'), false); })
          .finally(function () { busy(el, false); });
    }
    function renderPhpResult(r) {
        var box = byId('phpTestResult'); if (!box) { return; }
        if (!r) { box.innerHTML = ''; return; }
        if (r.valid) {
            settingsMsg('phpMsg', stxt('php.ok', 'The PHP CLI binary is valid.'), true);
            box.innerHTML = '<div class="updater-inline-ok">✓ PHP ' + esc(r.version) + ' · ' + esc(r.real_path || '') + '</div>'
                + '<div class="updater-pkg-meta">' + esc(stxt('guardianMin', 'Guardian minimum')) + ': ' + esc(r.guardian_min) + ' · '
                + esc(stxt('typo3Min', 'TYPO3 minimum')) + ': ' + esc(r.typo3_min) + ' · '
                + esc(stxt('satisfiesTypo3', 'satisfies TYPO3')) + ': ' + (r.satisfies_typo3 ? '✓' : '✕') + '</div>';
        } else {
            settingsMsg('phpMsg', phpReason(r.error_code), false);
            box.innerHTML = '<div class="updater-inline-err">✕ ' + esc(phpReason(r.error_code)) + (r.version ? (' (PHP ' + esc(r.version) + ')') : '') + '</div>';
        }
    }
    function phpSave(el) {
        var url = endpoint('phpSave'); if (!url || busy(el, true)) { return; }
        var path = (byId('runtimePhpBinary') || {}).value || '';
        settingsMsg('phpMsg', stxt('saving', 'Saving…'), null);
        postJson(url, { path: path }).then(function (d) {
            if (d && d.success === true) { settingsMsg('phpMsg', stxt('saved', 'Settings saved.'), true); loadRuntime(); }
            else if (d && d.result) { renderPhpResult(d.result); }
            else { settingsMsg('phpMsg', settingsError(d), false); }
        }).catch(function () { settingsMsg('phpMsg', t('js.error'), false); })
          .finally(function () { busy(el, false); });
    }

    /* ── Standalone recovery panel management ────────────────────── */

    var REC = { backup: null, components: {}, canRecover: false, dryOk: false };

    // Kept for backward compatibility with any markup that still references the
    // old read-only standalone URL element.
    function setStandaloneUrl() {
        var el = byId('updaterStandaloneUrl');
        if (!el) { return; }
        el.textContent = '/' + CFG.standaloneFilename;
        el.href = window.location.protocol + '//' + window.location.host + '/' + CFG.standaloneFilename;
    }

    function panelMsg(id, text, ok) {
        var el = byId(id);
        if (el) { el.textContent = text || ''; el.className = 'updater-inline-msg ' + (ok ? 'updater-inline-ok' : 'updater-inline-err'); }
    }

    function renderPanelState(d) {
        var st = byId('guardianPanelState');
        if (st) {
            var badge = d.deployed
                ? '<span class="updater-status-badge updater-status-ok">' + esc(t('js.panel.deployed')) + '</span>'
                : '<span class="updater-status-badge updater-status-idle">' + esc(t('js.panel.notDeployed')) + '</span>';
            st.innerHTML = badge + ' · ' + esc(d.enabled ? t('js.panel.enabled') : t('js.panel.disabled'));
        }
        setVal('guardianPanelFilename', d.filename || '');
        if (d.filename) { CFG.standaloneFilename = d.filename; }
        var link = byId('guardianPanelUrl');
        if (link && d.url) { link.textContent = '/' + (d.filename || ''); link.href = d.url; }
        var ts = byId('guardianTokenState');
        if (ts) {
            var tk = d.token || {};
            ts.innerHTML = tk.exists
                ? esc(t('js.source')) + ': <code>' + esc(tk.source) + '</code> · ' + esc(t('js.token.preview')) + ': <code>' + esc(tk.preview || '') + '</code>'
                : '<span class="updater-inline-warn">' + esc(t('js.token.none')) + '</span>';
        }
    }

    function loadPanelStatus() {
        var url = endpoint('panelStatus'); if (!url) { return; }
        postJson(url).then(function (d) { if (d.success) { renderPanelState(d); } }).catch(function () {});
    }

    function panelAction(name, body, msgId) {
        var url = endpoint(name); if (!url) { return; }
        postJson(url, body || {}).then(function (d) {
            if (d.success) {
                renderPanelState(d);
                panelMsg(msgId, t('js.saved'), true);
                if (d.plainToken) { showRevealOnce(d.plainToken); }
            } else {
                panelMsg(msgId, d.error || t('js.error'), false);
            }
        }).catch(function () { panelMsg(msgId, t('js.error'), false); });
    }

    function showRevealOnce(token) {
        var box = byId('guardianTokenReveal');
        if (!box) { return; }
        box.innerHTML = '<div class="updater-token-display" style="word-break:break-all;">' + esc(token) + '</div>'
            + '<p class="updater-inline-warn" style="font-size:.82rem;">' + esc(t('js.token.copyNow')) + '</p>';
    }

    function savePanelFilename() { panelAction('panelSaveFilename', { filename: (byId('guardianPanelFilename') || {}).value || '' }, 'guardianPanelMsg'); }
    function enablePanel() { panelAction('panelDeploy', {}, 'guardianPanelMsg'); }
    function disablePanel() { panelAction('panelDisable', {}, 'guardianPanelMsg'); }
    function generateToken() { panelAction('panelTokenGenerate', {}, 'guardianPanelMsg'); }
    function rotateToken() { if (!window.confirm(t('js.token.rotateConfirm'))) { return; } panelAction('panelRotate', {}, 'guardianPanelMsg'); }

    /* ── In-backend recovery flow (reuses shared recovery services) ── */

    function loadRecoveryBackups() {
        var url = endpoint('recoveryList'); if (!url) { return; }
        var box = byId('guardianRecoveryBackups'); if (!box) { return; }
        postJson(url).then(function (d) {
            if (!d.success || !d.backups || !d.backups.length) {
                box.innerHTML = '<div class="updater-empty">' + esc(t('js.recovery.none')) + '</div>';
                return;
            }
            box.innerHTML = d.backups.map(function (b) {
                var when = String(b.created_at || '').replace('T', ' ').replace(/\+.*$/, '');
                return '<label class="updater-rec-row" style="display:flex;gap:.5rem;align-items:center;padding:.3rem 0;cursor:pointer;">'
                    + '<input type="radio" name="guardianRecPick" value="' + esc(String(b.id)) + '"> '
                    + '<span><strong>' + esc(String(b.id)) + '</strong> · ' + esc(b.type || '') + ' · ' + esc(when) + '</span></label>';
            }).join('');
            box.querySelectorAll('input[name="guardianRecPick"]').forEach(function (r) {
                r.addEventListener('change', function () { selectRecoveryBackup(d.backups, r.value); });
            });
        }).catch(function () {});
    }

    function selectRecoveryBackup(list, id) {
        REC.backup = null;
        for (var i = 0; i < list.length; i++) { if (String(list[i].id) === id) { REC.backup = list[i]; break; } }
        REC.components = {};
        REC.canRecover = false;
        var box = byId('guardianRecoveryComponents'); if (box) { box.innerHTML = ''; }
        byId('guardianRecoveryChecks') && (byId('guardianRecoveryChecks').innerHTML = '');
        var cbx = byId('guardianRecoveryConfirmBox'); if (cbx) { cbx.style.display = 'none'; }
        var raw = (REC.backup && REC.backup.components) || {};
        var comps = Array.isArray(raw) ? raw : Object.keys(raw).filter(function (k) { return raw[k]; });
        // vendor is NEVER a component checkbox; it is controlled by the strategy.
        comps.filter(function (c) { return c !== 'vendor'; }).forEach(function (c) {
            var lbl = document.createElement('label'); lbl.className = 'updater-chk'; lbl.style.display = 'block';
            var cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = true;
            cb.addEventListener('change', function () { REC.components[c] = cb.checked; REC.dryOk = false; updateRecoveryRun(); });
            REC.components[c] = true;
            lbl.appendChild(cb); lbl.appendChild(document.createTextNode(' ' + c));
            if (box) { box.appendChild(lbl); }
        });
        REC.dryOk = false;
        var pf = document.querySelector('[data-action="recovery-preflight"]'); if (pf) { pf.disabled = !REC.backup; }
    }

    function vendorStrategy() {
        var el = byId('guardianVendorStrategy');
        return el ? el.value : 'rebuild';
    }

    function recoveryPayload(extra) {
        var p = { backupId: REC.backup.id, components: REC.components, vendorStrategy: vendorStrategy() };
        if (extra) { Object.keys(extra).forEach(function (k) { p[k] = extra[k]; }); }
        return p;
    }

    function runRecoveryPreflight() {
        if (!REC.backup) { return; }
        var url = endpoint('recoveryPreflight'); if (!url) { return; }
        postJson(url, recoveryPayload()).then(function (d) {
            var box = byId('guardianRecoveryChecks'); if (!box) { return; }
            if (!d.success || !d.preflight) { box.innerHTML = '<div class="updater-check updater-check-error">' + esc(t('js.error')) + '</div>'; return; }
            var pf = d.preflight;
            box.innerHTML = (pf.checks || []).map(function (ch) {
                return '<div class="updater-check updater-check-' + esc(ch.severity) + '">• ' + esc(ch.message) + '</div>';
            }).join('');
            REC.canRecover = !!pf.canRecover;
            REC.dryOk = false;
            var cbx = byId('guardianRecoveryConfirmBox'); if (cbx) { cbx.style.display = pf.canRecover ? '' : 'none'; }
            updateRecoveryRun();
        }).catch(function () {});
    }

    function runRecoveryDryRun() {
        if (!REC.backup) { return; }
        var url = endpoint('recoveryDryRun'); if (!url) { return; }
        var msg = byId('guardianRecoveryDryMsg'); if (msg) { msg.textContent = t('js.recovery.dryRunning'); }
        postJson(url, recoveryPayload()).then(function (d) {
            var box = byId('guardianRecoveryDry'); if (box) { box.innerHTML = ''; }
            if (!d.success) { REC.dryOk = false; if (msg) { msg.textContent = d.error || t('js.error'); } updateRecoveryRun(); return; }
            if (box) {
                box.innerHTML = (d.dryRun.checks || []).map(function (ch) {
                    return '<div class="updater-check updater-check-' + esc(ch.severity) + '">• ' + esc(ch.message) + '</div>';
                }).join('');
            }
            REC.dryOk = !!d.dryRun.ok;
            if (msg) { msg.textContent = REC.dryOk ? t('js.recovery.dryOk') : t('js.recovery.dryBlocked'); }
            updateRecoveryRun();
        }).catch(function () { REC.dryOk = false; updateRecoveryRun(); });
    }

    function updateRecoveryRun() {
        var phrase = (byId('guardianRecoveryPhrase') || {}).value || '';
        var confirmed = (byId('guardianRecoveryConfirm') || {}).checked;
        var vphraseOk = vendorStrategy() !== 'archived' || ((byId('guardianVendorPhrase') || {}).value || '').trim().toUpperCase() === 'RESTORE VENDOR';
        var vp = byId('guardianVendorPhraseWrap'); if (vp) { vp.style.display = vendorStrategy() === 'archived' ? '' : 'none'; }
        var btn = document.querySelector('[data-action="recovery-run"]');
        if (btn) { btn.disabled = !(REC.canRecover && REC.dryOk && phrase.trim().toUpperCase() === 'RECOVER' && confirmed && vphraseOk); }
    }

    function runRecovery() {
        if (!REC.backup) { return; }
        if (!REC.dryOk) { return; }
        if (!window.confirm(t('js.recovery.confirm'))) { return; }
        var url = endpoint('recoveryRun'); if (!url) { return; }
        var btn = document.querySelector('[data-action="recovery-run"]'); if (btn) { btn.disabled = true; }
        var log = byId('guardianRecoveryLog'); if (log) { log.style.display = ''; log.textContent = t('js.recovery.running'); }
        panelMsg('guardianRecoveryMsg', '', true);
        postJson(url, recoveryPayload({
            confirm: (byId('guardianRecoveryConfirm') || {}).checked,
            phrase: (byId('guardianRecoveryPhrase') || {}).value,
            vendorPhrase: (byId('guardianVendorPhrase') || {}).value
        })).then(function (d) {
            var res = byId('guardianRecoveryResult');
            if (!d.success) {
                if (res) { res.innerHTML = '<span class="updater-inline-err">' + esc(d.error || t('js.error')) + '</span>'; }
                if (log) { log.textContent = ''; }
                return;
            }
            if (res) { res.innerHTML = '<span class="updater-inline-ok">' + esc(t('js.recovery.done')) + '</span> ' + esc((d.result.restored || []).join(', ')); }
            if (log) { log.textContent = (d.result.log || []).join('\n'); }
            reloadBackupList();
        }).catch(function () { var l = byId('guardianRecoveryLog'); if (l) { l.textContent = t('js.error'); } });
    }

    function loadRecoveryInterrupted() {
        var url = endpoint('recoveryInterrupted'); if (!url) { return; }
        var box = byId('guardianRecoveryInterrupted'); if (!box) { return; }
        postJson(url).then(function (d) {
            if (!d.success || !d.incomplete || !d.incomplete.length) { box.style.display = 'none'; box.innerHTML = ''; return; }
            var t0 = d.incomplete[0];
            box.style.display = '';
            box.innerHTML = '<div class="updater-inline-err" style="font-weight:600;">' + esc(t('js.recovery.interrupted')) + '</div>'
                + '<div class="muted" style="font-size:.85rem;margin:.3rem 0;">' + esc(String(t0.job_id || '')) + ' · ' + esc(String(t0.step || '')) + '</div>'
                + '<button type="button" class="updater-btn updater-btn-delete" data-action="recovery-rollback-interrupted" data-job="' + esc(String(t0.job_id || '')) + '">↩️ ' + esc(t('js.recovery.rollbackInterrupted')) + '</button> <span id="guardianInterruptedMsg" class="updater-inline-msg"></span>';
        }).catch(function () {});
    }

    function rollbackInterruptedRecovery(el) {
        var jobId = el.getAttribute('data-job');
        if (!jobId || !window.confirm(t('js.recovery.rollbackConfirm'))) { return; }
        var url = endpoint('recoveryRollbackInterrupted'); if (!url) { return; }
        el.disabled = true;
        var msg = byId('guardianInterruptedMsg'); if (msg) { msg.textContent = t('js.update.rollingBack'); }
        postJson(url, { jobId: jobId }).then(function (d) {
            if (msg) { msg.textContent = d.success ? t('js.recovery.rollbackDone') : (d.error || t('js.error')); msg.className = 'updater-inline-msg ' + (d.success ? 'updater-inline-ok' : 'updater-inline-err'); }
            if (d.success) { loadRecoveryInterrupted(); }
        }).catch(function () { if (msg) { msg.textContent = t('js.error'); } });
    }

    /* ── Update jobs: start, poll, live log, rollback ────────────────── */

    var JOB = { polling: false, logOffset: 0, busy: false, lastJob: null, reportOpen: false, retry: 0, requestInFlight: false };

    function updateBusy(on) {
        JOB.busy = on;
        ['update-dry-run', 'update-open', 'update-check', 'update-start'].forEach(function (a) {
            var b = document.querySelector('[data-action="' + a + '"]');
            if (b) { b.disabled = on; }
        });
        var live = byId('updaterRunLiveBtn');
        if (live && !on) { live.disabled = !updateState.canRunLive; }
    }

    function updateMsg(text, ok) {
        var el = byId('updaterUpdateMsg');
        if (el) { el.textContent = text || ''; el.className = 'updater-inline-msg ' + (ok ? 'updater-inline-ok' : 'updater-inline-err'); }
    }

    function collectSelectedPackages() {
        var out = [];
        document.querySelectorAll('#updaterSelectivePackages input[type="checkbox"]:checked').forEach(function (cb) {
            if (cb.value) { out.push(cb.value); }
        });
        return out;
    }

    function currentMode() {
        var sel = document.querySelector('input[name="updateMode"]:checked');
        return sel ? sel.value : 'full';
    }

    function startDryRun() {
        var url = endpoint('updateDryRun'); if (!url) { return; }
        updateBusy(true);
        updateMsg(t('js.update.starting'), true);
        postJson(url, { mode: currentMode(), packages: collectSelectedPackages() }).then(function (d) {
            if (!d.success) { updateBusy(false); updateMsg(d.error || t('js.error'), false); return; }
            updateState.dryRunStatus = 'succeeded';
            updateState.canRunLive = true;
            updateMsg('', true);
            beginJobPolling();
        }).catch(function () { updateBusy(false); updateMsg(t('js.error'), false); });
    }

    function startRealUpdate() {
        var url = endpoint('updateStart'); if (!url) { updateMsg('Live update route is unavailable.', false); return; }
        if (!updateState.canRunLive || !updateState.selectedTargetVersion) { updateMsg('Select a target version and complete a successful dry run first.', false); return; }
        if (!(byId('updaterConfirmUpdate') || {}).checked) { return; }
        if (!window.confirm(t('js.update.confirm'))) { return; }
        updateBusy(true);
        updateMsg(t('js.update.starting'), true);
        postJson(url, {
            mode: currentMode(),
            targetVersion: updateState.selectedTargetVersion,
            upgradeType: updateState.selectedUpgradeType,
            packages: collectSelectedPackages(),
            snapshotVendor: (byId('updaterSnapshotVendor') || {}).checked,
            sendRecoveryEmail: (byId('updaterSendRecoveryEmail') || {}).checked,
            confirm: true
        }).then(function (d) {
            if (!d.success) { updateBusy(false); updateMsg(d.error || t('js.error'), false); return; }
            closeUpdateModal();
            updateMsg('', true);
            beginJobPolling();
        }).catch(function () { updateBusy(false); updateMsg(t('js.error'), false); });
    }

    function beginJobPolling() {
        JOB.logOffset = 0;
        JOB.polling = true;
        JOB.lastJob = null;
        JOB.reportOpen = true;
        var box = byId('updaterJobActive');
        if (box) { box.hidden = false; box.classList.add('is-open'); box.style.display = 'block'; box.innerHTML = ''; }
        pollJobOnce();
    }

    function stepLabel(s) { return t('js.step.' + s) !== 'js.step.' + s ? t('js.step.' + s) : s; }
    function isTerminalStatus(status) { return status === 'succeeded' || status === 'failed' || status === 'warning'; }
    function stepStateClass(state) {
        return state === 'complete' ? 'done'
            : state === 'failed' ? 'fail'
            : state === 'active' ? 'active'
            : state === 'warning' ? 'warn'
            : 'idle';
    }
    function stepStateIcon(state) {
        return state === 'complete' ? '✓'
            : state === 'failed' ? '✖'
            : state === 'active' ? '▶'
            : state === 'warning' ? '⚠'
            : '○';
    }
    // Resolve every step's visual state. Priority:
    //   1. authoritative structured step_states from the server;
    //   2. otherwise infer from status + current/failed step index;
    //   3. for older terminal jobs lacking structured data, upgrade steps the
    //      persisted log proves finished (e.g. "[step] verify:", "[info] verify:
    //      Resulting TYPO3 version:", "[info] maintenance_off: Maintenance mode
    //      disabled") to complete — a monotonic, never-downgrading safety net.
    function resolveStepStates(job, logText) {
        var steps = job.steps || [];
        if (Object.prototype.toString.call(job.step_states) === '[object Array]' && job.step_states.length === steps.length) {
            return job.step_states.slice();
        }
        var curIdx = job.current_step ? steps.indexOf(job.current_step) : -1;
        var failedIdx = job.failed_step ? steps.indexOf(job.failed_step) : -1;
        var states = steps.map(function (s, i) {
            if (job.status === 'succeeded') { return 'complete'; }
            if (job.status === 'failed') {
                var marker = failedIdx !== -1 ? failedIdx : curIdx;
                if (marker === -1) { return 'pending'; }
                return i < marker ? 'complete' : (i === marker ? 'failed' : 'pending');
            }
            if (curIdx === -1) { return 'pending'; }
            return i < curIdx ? 'complete' : (i === curIdx ? 'active' : 'pending');
        });
        if (isTerminalStatus(job.status) && logText) {
            inferCompletedStepsFromLog(steps, states, logText, failedIdx);
        }
        return states;
    }
    function inferCompletedStepsFromLog(steps, states, logText, failedIdx) {
        var maxStarted = -1;
        steps.forEach(function (s, i) {
            var re = new RegExp('\\]\\s*' + s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*:');
            if (re.test(logText) && i > maxStarted) { maxStarted = i; }
        });
        if (maxStarted < 0) { return; }
        for (var i = 0; i <= maxStarted; i++) {
            if (failedIdx !== -1 && i >= failedIdx) { break; }
            if (states[i] !== 'failed') { states[i] = 'complete'; }
        }
    }
    function stepListHtml(job, logText) {
        var steps = job.steps || [];
        var states = resolveStepStates(job, logText);
        return steps.map(function (s, i) {
            var state = states[i] || 'pending';
            return '<span class="updater-step updater-step-' + stepStateClass(state) + '">' + stepStateIcon(state) + ' ' + esc(stepLabel(s)) + '</span>';
        }).join(' ');
    }

    function renderJob(job) {
        var box = byId('updaterJobActive');
        if (!box) { return; }
        if (!job) {
            if (JOB.lastJob) { job = JOB.lastJob; }
            else { box.style.display = 'none'; box.innerHTML = ''; return; }
        }
        JOB.lastJob = job;
        JOB.reportOpen = true;
        box.style.display = 'block';
        box.hidden = false;
        box.classList.add('is-open');
        var cls = job.status === 'failed' ? 'error' : (job.status === 'succeeded' ? 'ok' : 'idle');
        var head = '<div class="updater-job-card"><div class="updater-job-status">'
            + '<span class="updater-status-badge updater-status-' + cls + '">' + esc(t('js.jobstatus.' + job.status) !== 'js.jobstatus.' + job.status ? t('js.jobstatus.' + job.status) : job.status) + '</span> '
            + '<span class="updater-job-mode">' + esc((job.display_label || (job.execution_type === 'dry_run' ? 'Dry run' : 'Real update')) + ' · ' + (job.mode || '')) + '</span>'
            + '<span class="updater-job-progress"> · ' + (job.progress != null ? job.progress : 0) + '%</span></div>'
            + '<div class="updater-job-steps">' + stepListHtml(job, JOB._logBuffer) + '</div>';
        if (job.error) { head += '<div class="updater-inline-err" style="margin-top:.4rem;">' + esc(job.error) + '</div>'; }
        if (job.status === 'failed' && job.safety_backup) {
            head += '<div style="margin-top:.5rem;"><button type="button" class="updater-btn updater-btn-secondary" data-action="update-rollback" data-job="' + esc(job.id) + '">↩️ ' + esc(t('js.update.rollback')) + '</button>'
                + ' <span class="updater-inline-msg" id="updaterRollbackMsg"></span></div>';
        }
        head += '</div><pre id="updaterJobLog" class="updater-cron-cmd" style="max-height:280px;overflow:auto;white-space:pre-wrap;margin-top:.6rem;"></pre>';
        box.innerHTML = head;
        var log = byId('updaterJobLog');
        if (log && JOB._logBuffer) { log.textContent = JOB._logBuffer; log.scrollTop = log.scrollHeight; }
    }

    function appendLog(entries) {
        if (!entries || !entries.length) { return; }
        JOB._logBuffer = (JOB._logBuffer || '');
        entries.forEach(function (e) {
            JOB._logBuffer += '[' + (e.level || 'info') + '] ' + (e.step ? (e.step + ': ') : '') + (e.msg || '') + '\n';
        });
        var log = byId('updaterJobLog');
        if (log) { log.textContent = JOB._logBuffer; log.scrollTop = log.scrollHeight; }
    }

    function pollJobOnce() {
        if (JOB.requestInFlight) { return; }
        var statusUrl = endpoint('updateJobStatus') || endpoint('jobStatus');
        var logUrl = endpoint('updateJobLog');
        if (!statusUrl) { return; }
        JOB.requestInFlight = true;
        postJson(statusUrl).then(function (d) {
            JOB.retry = 0;
            var job = d && d.job ? d.job : null;
            renderJob(job);
            var chain = Promise.resolve();
            if (logUrl) {
                chain = fetch(logUrl + (logUrl.indexOf('?') === -1 ? '?' : '&') + 'offset=' + JOB.logOffset, {
                    method: 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: '{}'
                }).then(function (r) { return r.json(); }).then(function (l) {
                    if (l && l.success) { appendLog(l.entries); if (typeof l.offset === 'number') { JOB.logOffset = l.offset; } }
                }).catch(function () {});
            }
            chain.then(function () {
                if (job && (job.status === 'running' || job.status === 'queued')) {
                    setTimeout(pollJobOnce, 2000);
                } else {
                    JOB.polling = false;
                    updateBusy(false);
                    // Terminal jobs keep the report expanded; only explicit
                    // user actions or starting another job may replace it.
                    var report = byId('updaterJobActive');
                    if (report) { report.hidden = false; report.style.display = 'block'; report.classList.add('is-open'); }
                    // The active status poll returns null once the job is archived,
                    // so fetch the authoritative persisted job and re-render the
                    // report before stopping — every completed step then shows
                    // complete instead of freezing at the last polled step.
                    finalizeMainJob();
                    loadJobArchive();
                }
            });
        }).catch(function () {
            JOB.retry = Math.min(JOB.retry + 1, 6);
            if (JOB.polling) {
                updateMsg(JOB.retry > 1 ? 'Reconnecting…' : 'Waiting for server response…', false);
                setTimeout(pollJobOnce, Math.min(30000, 1000 * Math.pow(2, JOB.retry)));
            }
        }).finally(function () { JOB.requestInFlight = false; });
    }

    function rollbackJob(el) {
        var jobId = el.getAttribute('data-job');
        if (!jobId || !window.confirm(t('js.update.rollbackConfirm'))) { return; }
        var url = endpoint('updateRollback'); if (!url) { return; }
        el.disabled = true;
        var msg = byId('updaterRollbackMsg');
        if (msg) { msg.textContent = t('js.update.rollingBack'); msg.className = 'updater-inline-msg'; }
        postJson(url, { jobId: jobId }).then(function (d) {
            if (msg) { msg.textContent = d.success ? t('js.update.rollbackDone') : (d.error || t('js.error')); msg.className = 'updater-inline-msg ' + (d.success ? 'updater-inline-ok' : 'updater-inline-err'); }
        }).catch(function () { if (msg) { msg.textContent = t('js.error'); msg.className = 'updater-inline-msg updater-inline-err'; } });
    }

    function runUpdateCheck() {
        var url = endpoint('updateCheck'); if (!url) { return; }
        updateMsg(t('js.update.checking'), true);
        postJson(url).then(function (d) {
            if (!d.success) {
                updateState.onlineCheckComplete = false;
                updateState.upgradePaths = [];
                updateState.selectedTargetVersion = null;
                updateState.canRunLive = false;
                var failedPaths = byId('updaterUpgradePaths');
                if (failedPaths) { failedPaths.style.display = 'block'; failedPaths.textContent = d.message || d.error || 'Unable to retrieve TYPO3 release metadata.'; }
                updateMsg(d.message || d.error || t('js.error'), false);
                return;
            }
            if (d.error || d.status === 'error' || d.error_code) {
                updateState.onlineCheckComplete = false;
                updateState.upgradePaths = [];
                updateState.selectedTargetVersion = null;
                updateState.canRunLive = false;
                var pathsBox = byId('updaterUpgradePaths');
                if (pathsBox) { pathsBox.style.display = 'block'; pathsBox.textContent = d.error || 'Unable to retrieve TYPO3 release metadata.'; }
                updateMsg(d.error || 'Unable to retrieve TYPO3 release metadata.', false);
                return;
            }
            updateState.onlineCheckComplete = true;
            updateState.installedVersion = d.upgradePaths ? d.upgradePaths.installedVersion : null;
            updateState.upgradePaths = d.upgradePaths ? [d.upgradePaths.latestCurrentMajor, d.upgradePaths.nextMajor] : [];
            renderUpgradePaths(d.upgradePaths);
            packages = (d.packages || []).map(function (p) {
                return { name: p.name, current: p.current, latest: p.latest, has_update: !!p.has_update, abandoned: !!p.abandoned, status: p.status, type: p.type };
            });
            renderPackages(null);
            populateSelectivePackages();
            updateMsg(d.error ? (d.error) : t('js.update.checkDone'), !d.error);
        }).catch(function () { updateMsg(t('js.error'), false); });
    }

    function renderUpgradePaths(paths) {
        var box = byId('updaterUpgradePaths');
        if (!box || !paths) { return; }
        var options = [paths.latestCurrentMajor, paths.nextMajor].filter(function (p) { return p && p.available; });
        box.style.display = 'block';
        box.innerHTML = '<strong>TYPO3 upgrade paths</strong>' + (options.length ? options.map(function (p, i) {
            return '<label style="display:block;padding:.5rem 0;"><input type="radio" name="guardianTargetVersion" value="' + esc(p.version) + '" data-upgrade-type="' + esc(p.upgradeType) + '"> ' + esc(p.version) + ' · ' + esc(p.upgradeType) + '</label>';
        }).join('') : '<p>No newer stable TYPO3 release was found.</p>');
        box.querySelectorAll('input[name="guardianTargetVersion"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                updateState.selectedTargetVersion = radio.value;
                updateState.selectedUpgradeType = radio.dataset.upgradeType;
                updateState.dryRunStatus = 'not_run'; updateState.canRunLive = false;
                var dry = byId('updaterDryRunBtn'); if (dry) { dry.disabled = false; }
                var live = byId('updaterRunLiveBtn'); if (live) { live.disabled = true; }
            });
        });
    }

    function populateSelectivePackages() {
        var box = byId('updaterSelectivePackages');
        if (!box) { return; }
        var list = (packages || []).filter(function (p) { return p.has_update; });
        if (!list.length) { box.innerHTML = '<div style="text-align:center;color:var(--updater-text-muted);padding:1rem;">' + esc(t('js.update.noUpdates')) + '</div>'; return; }
        box.innerHTML = list.map(function (p) {
            return '<label class="updater-chk" style="display:flex;gap:.4rem;padding:.2rem 0;">'
                + '<input type="checkbox" value="' + esc(p.name) + '"> <span><strong>' + esc(p.name) + '</strong> '
                + '<span style="color:var(--updater-text-soft);">' + esc(p.current) + ' → ' + esc(p.latest || '?') + '</span></span></label>';
        }).join('');
    }

    function loadJobStatus() {
        // On load, resume polling if a job is already running (survives reload).
        var url = endpoint('jobStatus'); if (!url) { return; }
        postJson(url).then(function (d) {
            if (d.success && d.job && (d.job.status === 'running' || d.job.status === 'queued')) {
                JOB._logBuffer = '';
                beginJobPolling();
            } else if (d.success && d.job) {
                renderJob(d.job);
            }
        }).catch(function () {});
    }

    // Fetch the authoritative persisted final job by id and re-render the active
    // report from it. Used when a job reaches a terminal state: the live status
    // slot is already cleared (archived), so only the persisted record carries the
    // complete final step/status data.
    function finalizeMainJob() {
        var url = endpoint('updateJobDetails');
        var id = JOB.lastJob && JOB.lastJob.id ? JOB.lastJob.id : null;
        if (!url || !id) { return; }
        var u = url + (url.indexOf('?') === -1 ? '?' : '&') + 'id=' + encodeURIComponent(id);
        postJson(u).then(function (d) {
            if (d && d.success && d.job) { renderJob(d.job); }
        }).catch(function () {});
    }

    function loadJobArchive() {
        var url = endpoint('updateJobs') || endpoint('jobArchive'); if (!url) { return; }
        var box = byId('updaterJobArchive');
        postJson(url).then(function (data) {
            if (!box) { return; }
            if (data.success && data.jobs && data.jobs.length) {
                box.innerHTML = data.jobs.map(function (j) {
                    var when = String(j.finished_at || j.created_at || '').replace('T', ' ').replace(/\+.*$/, '');
                    var st = t('js.jobstatus.' + j.status) !== 'js.jobstatus.' + j.status ? t('js.jobstatus.' + j.status) : j.status;
                    var kind = j.execution_type === 'dry_run' ? 'Dry Run' : (j.execution_type === 'real_update' ? 'Live Update' : 'Legacy update job');
                    return '<div class="updater-job-archive-row" data-action="job-details" data-job-id="' + esc(j.id) + '" tabindex="0" role="button"><strong>' + esc(kind) + '</strong> · ' + esc(j.mode || '') + ' · ' + esc(st) + ' · ' + esc(j.previous_typo3 || '') + (j.result_typo3 ? ' → ' + esc(j.result_typo3) : '') + ' · ' + esc(when) + '</div>';
                }).join('');
            } else {
                box.innerHTML = '<div class="updater-empty">' + esc(t('js.jobs.none')) + '</div>';
            }
        }).catch(function () {
            if (box) { box.innerHTML = '<div class="updater-empty">' + esc(t('js.jobs.unavailable')) + '</div>'; }
        });
    }

    function openStoredJob(el) {
        var url = endpoint('updateJobDetails');
        var id = el && el.dataset ? el.dataset.jobId : '';
        if (!url || !id) { return; }
        var box = byId('updaterJobActive');
        postJson(url, {jobId: id}).then(function (d) {
            if (d.success && d.job) { JOB.lastJob = d.job; JOB.reportOpen = true; renderJob(d.job); }
            else { updateMsg((d && d.error) || 'Job details could not be loaded.', false); }
        }).catch(function () { updateMsg('Job details could not be loaded.', false); });
    }

    function backupRowHtml(b) {
        var id = String(b.id || '');
        var when = String(b.created_at || '').replace('T', ' ').replace(/\+.*$/, '');
        var download = b.download_url
            ? '<a class="updater-btn updater-btn-secondary" style="padding:.35rem .8rem;font-size:.8rem;" href="' + esc(b.download_url) + '">' + esc(t('js.backup.download')) + '</a>'
            : '';
        return '<div class="updater-backup-row">'
            + '<div><strong>' + esc(id) + '</strong>'
            + '<div style="font-size:.8rem;color:var(--updater-text-soft);">' + esc(b.type || '') + ' · '
            + esc(b.archive_size_human || '') + ' · ' + esc(t('js.backup.files')) + ' ' + esc(String(b.file_count != null ? b.file_count : '?')) + '</div></div>'
            + '<span style="color:var(--updater-text-soft);font-size:.85rem;">' + esc(when) + '</span>'
            + '<span>' + download
            + ' <button type="button" class="updater-btn updater-btn-secondary" style="padding:.35rem .8rem;font-size:.8rem;" data-action="backup-details" data-id="' + esc(id) + '">' + esc(t('js.backup.details')) + '</button></span>'
            + '<button type="button" class="updater-btn updater-btn-delete" data-action="backup-delete" data-id="' + esc(id) + '">' + esc(t('js.backup.delete')) + '</button>'
            + '</div>';
    }

    function renderBackupList(list) {
        ['updaterBackupList', 'updaterRecoveryBackupList'].forEach(function (id) {
            var box = byId(id); if (!box) { return; }
            box.innerHTML = (!list || !list.length) ? '<div class="updater-empty">' + esc(t('js.backups.none')) + '</div>' : list.map(backupRowHtml).join('');
        });
    }

    function reloadBackupList() {
        var url = endpoint('backupList'); if (!url) { return; }
        postJson(url).then(function (data) {
            if (data.success) { renderBackupList(data.backups || []); }
        }).catch(function () {});
    }

    function collectBackupComponents() {
        var config = !!(byId('updaterBkpTemplates') && byId('updaterBkpTemplates').checked);
        return {
            composerJson: true,
            composerLock: true,
            database: true,
            vendor: !!(byId('updaterBkpVendor') && byId('updaterBkpVendor').checked),
            configuration: config,
            packages: config,
            templates: false,
            fileadmin: !!(byId('updaterBkpFileadmin') && byId('updaterBkpFileadmin').checked),
            publicAssets: !!(byId('updaterBkpAssets') && byId('updaterBkpAssets').checked)
        };
    }

    function createBackup() {
        var url = endpoint('backupCreate'); if (!url) { return; }
        var components = collectBackupComponents();
        var confirmMsg = t('js.backup.confirm');
        if (components.fileadmin) { confirmMsg += '\n\n' + t('js.backup.confirmFileadmin'); }
        if (!window.confirm(confirmMsg)) { return; }

        var btn = byId('updaterBackupBtn');
        var result = byId('updaterBackupResult');
        if (btn) { btn.disabled = true; btn.dataset.busy = '1'; btn.innerHTML = '<span class="updater-spinner"></span>' + esc(t('js.backup.creating')); }
        if (result) { result.innerHTML = '<div style="color:var(--updater-text-soft);padding:.8rem;">' + esc(t('js.backup.creatingWait')) + '</div>'; }

        postJson(url, {components: components}).then(function (data) {
            if (!data.success) {
                if (result) { result.innerHTML = '<div class="updater-result-summary error">❌ ' + esc(data.error || t('js.backup.failed')) + '</div>'; }
                return;
            }
            var m = data.manifest || {};
            var html = '<div class="updater-result-summary ok">✅ ' + esc(t('js.backup.created')) + ' <strong>' + esc(m.id || '') + '</strong> · ' + esc(m.archive_size_human || '') + '</div>';
            if (data.log && data.log.length) {
                html += '<div class="updater-backup-log">' + data.log.map(esc).join('\n') + '</div>';
            }
            if (result) { result.innerHTML = html; }
            reloadBackupList();
        }).catch(function (e) {
            if (result) { result.innerHTML = '<div class="updater-result-summary error">❌ ' + esc((e && e.message) || e) + '</div>'; }
        }).finally(function () {
            if (btn) { btn.disabled = false; delete btn.dataset.busy; btn.textContent = '💾 ' + t('js.backup.createAnother'); }
        });
    }

    function deleteBackup(el) {
        var url = endpoint('backupDelete'); if (!url) { return; }
        var id = el && el.dataset ? el.dataset.id : '';
        if (!id || !window.confirm(t('js.backup.confirmDelete') + '\n' + id)) { return; }
        postJson(url, {id: id}).then(function (data) {
            if (!data.success) { window.alert(t('js.backup.deleteFailed') + ': ' + (data.error || '')); return; }
            reloadBackupList();
        }).catch(function () {});
    }

    function showBackupDetails(el) {
        var url = endpoint('backupDetails'); if (!url) { return; }
        var id = el && el.dataset ? el.dataset.id : '';
        if (!id) { return; }
        postJson(url, {id: id}).then(function (data) {
            var result = byId('updaterBackupResult');
            if (!result) { return; }
            if (!data.success) { result.innerHTML = '<div class="updater-result-summary error">❌ ' + esc(data.error || '') + '</div>'; return; }
            var m = data.manifest || {};
            var rows = [
                ['ID', m.id], ['Type', m.type], ['Status', m.status], ['Created', m.created_at], ['Completed', m.completed_at],
                ['Files', m.file_count], ['Archive', m.archive_size_human], ['Database', m.database_method],
                ['TYPO3', m.typo3_version], ['PHP', m.php_version], ['Host', m.hostname], ['Checksum', m.checksum]
            ];
            var html = '<div class="updater-card"><h3>' + esc(m.id || '') + '</h3><dl style="margin:0;">';
            rows.forEach(function (r) {
                if (r[1] == null || r[1] === '') { return; }
                html += '<div style="display:flex;gap:1rem;padding:.15rem 0;"><dt style="min-width:110px;color:var(--updater-text-muted);">' + esc(r[0]) + '</dt><dd style="margin:0;word-break:break-all;">' + esc(String(r[1])) + '</dd></div>';
            });
            html += '</dl>';
            if (data.log && data.log.length) { html += '<div class="updater-backup-log">' + data.log.map(esc).join('\n') + '</div>'; }
            html += '</div>';
            result.innerHTML = html;
        }).catch(function () {});
    }

    /* ── Schedule save / run / test ──────────────────────────────── */

    function collectScheduleConfig() {
        function slot(P) {
            return {
                enabled: !!(byId('sched' + P + 'Enabled') && byId('sched' + P + 'Enabled').checked),
                frequency: (byId('sched' + P + 'Frequency') || {}).value || 'daily',
                time: (byId('sched' + P + 'Time') || {}).value || '03:00',
                weekday: parseInt((byId('sched' + P + 'Weekday') || {}).value || '1', 10),
                day_of_month: parseInt((byId('sched' + P + 'Dom') || {}).value || '1', 10),
                retention: parseInt((byId('sched' + P + 'Retention') || {}).value || '7', 10)
            };
        }
        var config = collectBackupComponents();
        var full = slot('Full');
        full.components = {
            vendor: !!(byId('schedFullCompVendor') && byId('schedFullCompVendor').checked),
            configuration: !!(byId('schedFullCompTemplates') && byId('schedFullCompTemplates').checked),
            packages: !!(byId('schedFullCompTemplates') && byId('schedFullCompTemplates').checked),
            templates: false,
            fileadmin: !!(byId('schedFullCompFiles') && byId('schedFullCompFiles').checked),
            publicAssets: !!(byId('schedFullCompAssets') && byId('schedFullCompAssets').checked)
        };
        return {
            mini: slot('Mini'),
            full: full,
            notifications: {
                email: (byId('schedEmail') || {}).value || '',
                sender_email: (byId('schedSenderEmail') || {}).value || '',
                sender_name: (byId('schedSenderName') || {}).value || 'Guardian',
                on_success: !!(byId('schedNotifySuccess') && byId('schedNotifySuccess').checked),
                on_failure: !!(byId('schedNotifyFailure') && byId('schedNotifyFailure').checked)
            }
        };
    }

    function saveSchedule() {
        var url = endpoint('scheduleSave'); if (!url) { return; }
        postJson(url, collectScheduleConfig()).then(function (data) {
            if (data.success) { loadSchedule(); window.alert(t('js.schedule.saved')); }
            else { window.alert(t('js.schedule.saveFailed') + ': ' + (data.error || '')); }
        }).catch(function () {});
    }

    function runSchedule(type) {
        var url = endpoint('scheduleRun'); if (!url) { return; }
        var stateEl = byId('sched' + (type === 'mini' ? 'Mini' : 'Full') + 'State');
        if (stateEl) { stateEl.innerHTML = '<div class="updater-sched-status">' + esc(t('js.schedule.running')) + '</div>'; }
        postJson(url, {type: type}).then(function (data) {
            if (stateEl) {
                stateEl.innerHTML = data.success
                    ? '<div class="updater-sched-status success">✅ ' + esc((data.manifest && data.manifest.id) || '') + '</div>'
                    : '<div class="updater-sched-status error">❌ ' + esc(data.error || '') + '</div>';
            }
            loadSchedule();
            reloadBackupList();
        }).catch(function () {});
    }

    function testEmail() {
        var url = endpoint('backupTestEmail'); if (!url) { return; }
        var result = byId('updaterTestEmailResult');
        if (result) { result.textContent = t('js.email.sending'); }
        postJson(url, collectScheduleConfig()).then(function (data) {
            if (!result) { return; }
            result.innerHTML = data.success
                ? '<span style="color:var(--updater-result-ok-fg);">✅ ' + esc(t('js.email.sent')) + ' ' + esc(data.recipient || '') + '</span>'
                : '<span style="color:var(--updater-result-err-fg);">❌ ' + esc(data.error || t('js.email.failed')) + '</span>';
        }).catch(function () {});
    }

    /* ── Delegated event handling (no inline JS) ─────────────────── */

    var ACTIONS = {
        tab: function (el) { switchTab(el.dataset.tab, true); },
        // Entitlement is not managed here: the shared V-T.ONE screen owns that,
        // and this only sends the administrator to it.
        'goto-licensing': function () {
            ensureConfig();
            if (CFG.licensingUrl) { window.location.href = CFG.licensingUrl; }
        },
        'upgrade-close': closeUpgradeModal,
        'update-open': function () { if (updateState.canRunLive) { openUpdateModal(); } },
        'update-close': closeUpdateModal,
        'update-dry-run': startDryRun,
        'update-start': startRealUpdate,
        'update-check': runUpdateCheck,
        'update-rollback': rollbackJob,
        'job-details': openStoredJob,
        'analyse': runAnalysis,
        'packages-load': loadDashboardPackages,
        'packages-filter': function () { renderManage(); },
        'manage-update': manageStart,
        'manage-remove': manageStart,
        'manage-disable': manageStateChange,
        'manage-enable': manageStateChange,
        'manage-confirm': manageConfirm,
        'manage-cancel': manageCancel,
        'manage-details': manageDetails,
        'ter-search': terSearch,
        'ter-install': terInstall,
        'ter-install-confirm': terConfirm,
        'ter-cancel': terCancel,
        'upload-start': uploadStart,
        'custom-dry-run': customDryRun,
        'custom-confirm': customConfirm,
        'ext-cancel': extCancel,
        'orphan-reuse': orphanReuse,
        'orphan-remove': orphanRemove,
        'guardian-self-disable': guardianSelfDisable,
        'guardian-uninstall': guardianUninstallStart,
        'guardian-uninstall-confirm': guardianUninstallConfirm,
        'notifications-save': saveNotifications,
        'notifications-test': testNotifications,
        'php-detect': phpDetect,
        'php-test': phpTest,
        'php-save': phpSave,
        'php-pick': phpPick,
        'backup-reload': reloadBackupList,
        'backup-create': createBackup,
        'backup-delete': deleteBackup,
        'backup-details': showBackupDetails,
        'schedule-save': saveSchedule,
        'schedule-run-mini': function () { runSchedule('mini'); },
        'schedule-run-full': function () { runSchedule('full'); },
        'backup-test-email': testEmail,
        'panel-save-filename': savePanelFilename,
        'panel-enable': enablePanel,
        'panel-disable': disablePanel,
        'panel-token-generate': generateToken,
        'panel-token-rotate': rotateToken,
        'recovery-preflight': runRecoveryPreflight,
        'recovery-dryrun': runRecoveryDryRun,
        'recovery-rollback-interrupted': rollbackInterruptedRecovery,
        'recovery-run': runRecovery
    };

    function onClick(e) {
        var el = e.target.closest('[data-action]');
        if (!el || !root().contains(el)) { return; }
        if (el.disabled) { return; }
        var action = el.dataset.action;
        if (ACTIONS[action]) {
            e.preventDefault();
            ACTIONS[action](el);
        }
    }

    function onInput(e) {
        var el = e.target.closest('[data-action]');
        if (el && el.dataset.action === 'packages-filter') { renderManage(); return; }
        if (e.target && (e.target.id === 'guardianRecoveryPhrase' || e.target.id === 'guardianVendorPhrase')) { updateRecoveryRun(); }
    }

    function onChange(e) {
        var el = e.target;
        if (el.id === 'schedMiniFrequency') { toggleScheduleRows('Mini'); }
        else if (el.id === 'schedFullFrequency') { toggleScheduleRows('Full'); }
        else if (el.name === 'updateMode') { onUpdateModeChange(); if (el.value === 'selective') { populateSelectivePackages(); } }
        else if (el.id === 'updaterConfirmUpdate') {
            var startBtn = document.querySelector('[data-action="update-start"]');
            if (startBtn) { startBtn.disabled = !el.checked; }
        }
        else if (el.id === 'schedFullCompFiles') {
            var warn = byId('schedFilesWarning');
            if (warn) { warn.style.display = el.checked ? '' : 'none'; }
        } else if (el.dataset && el.dataset.action === 'packages-filter') {
            renderManage();
        } else if (el.dataset && el.dataset.action === 'upload-select') {
            uploadSelect();
        } else if (el.id === 'guardianRecoveryConfirm' || el.id === 'guardianVendorStrategy') {
            REC.dryOk = false; updateRecoveryRun();
        }
    }

    /* ── Boot ────────────────────────────────────────────────────── */

    function boot() {
        var mount = document.getElementById('guardianModule');
        if (!mount) { return; }
        if (mount.dataset.guardianBooted === '1') { return; }
        mount.dataset.guardianBooted = '1';

        ensureConfig();

        document.addEventListener('click', onClick);
        document.addEventListener('input', onInput);
        document.addEventListener('change', onChange);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target && e.target.id === 'guardianTerQuery') { e.preventDefault(); terSearch(); }
        });
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && JOB.lastJob && JOB.polling && !JOB.requestInFlight) { pollJobOnce(); }
        });

        initTabs();
        applyLicenseGating();
        loadSchedule();
        reloadBackupList();
        loadRuntime();
        loadPanelStatus();
        loadRecoveryBackups();
        loadRecoveryInterrupted();
        loadJobStatus();
        loadJobArchive();
        setStandaloneUrl();
        toggleScheduleRows('Mini');
        toggleScheduleRows('Full');
    }

    if (document.readyState !== 'loading') {
        boot();
    } else {
        document.addEventListener('DOMContentLoaded', boot);
    }
})();
