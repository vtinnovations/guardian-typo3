<?php

/*
 * Guardian for TYPO3 — Standalone Recovery Panel entrypoint.
 *
 * GUARDIAN-RECOVERY-PANEL:MANAGED-ENTRYPOINT
 *
 * This file is DEPLOYED by Guardian into the public web root and is recognised by
 * Guardian through the marker line above (do not remove it). It is a THIN
 * bootstrap + controller: it contains NO restore engine of its own. All real work
 * (validate backup, preflight, safety snapshot, staged extraction, database
 * import, rollback, maintenance mode) is performed by the SAME Guardian recovery
 * services the backend uses, hand-wired by StandaloneRecoveryKernel.
 *
 * No secret is embedded in this file: the access token is stored hashed under
 * <project>/var/guardian/recovery-panel/ and only ever compared here.
 *
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

declare(strict_types=1);

// ── Fail-safe error handling (never leak stack traces / absolute paths) ───────
error_reporting(E_ALL);
ini_set('display_errors', '0');

const GUARDIAN_SESSION_INACTIVITY = 900;   // 15 min idle timeout
const GUARDIAN_SESSION_ABSOLUTE   = 3600;  // 1 h absolute session lifetime

function guardian_fail(string $message, int $status = 500): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
    }
    echo '<!doctype html><meta charset="utf-8"><title>Recovery</title>';
    echo '<body style="font:15px system-ui;background:#0d1117;color:#c9d1d9;padding:2rem">';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES) . '</p></body>';
    exit;
}

set_exception_handler(static function (\Throwable $e): void {
    // Deliberately generic — no class, message, file or line is shown.
    guardian_fail('The recovery panel encountered an internal error.', 500);
});

// ── Bootstrap: locate the Composer autoloader from the panel location ─────────
$publicDir = __DIR__;
$projectDir = \dirname($publicDir);
$autoload = null;
foreach ([
    $projectDir . '/vendor/autoload.php',
    \dirname($projectDir) . '/vendor/autoload.php',
] as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}
if ($autoload === null) {
    guardian_fail('Recovery is not available in this environment.', 503);
}
require $autoload;

use Vtinnovations\GuardianTypo3\Recovery\Standalone\StandaloneRecoveryKernel;

$kernel = new StandaloneRecoveryKernel(__FILE__);
$panelConfig = $kernel->panelConfig();

// Disabled by default: an un-enabled panel behaves like a file that isn't there.
if (!$panelConfig->isEnabled()) {
    guardian_fail('Not found.', 404);
}

$tokenStore = $kernel->tokenStore();
$rateLimiter = $kernel->rateLimiter();

// ── Session (HttpOnly, SameSite=Strict, Secure on HTTPS) ──────────────────────
$https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('guardian_recovery');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => $https,
]);
session_start();

function guardian_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function guardian_csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf'];
}

function guardian_check_csrf(): bool
{
    $sent = (string) ($_POST['csrf'] ?? ($_SERVER['HTTP_X_GUARDIAN_CSRF'] ?? ''));
    return $sent !== '' && !empty($_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], $sent);
}

function guardian_token_fingerprint(object $tokenStore): string
{
    $status = $tokenStore->status();
    return (string) ($status['created_at'] ?? $status['source'] ?? '');
}

function guardian_is_authenticated(object $tokenStore): bool
{
    if (empty($_SESSION['auth']) || $_SESSION['auth'] !== true) {
        return false;
    }
    $now = time();
    if (($now - (int) ($_SESSION['last_seen'] ?? 0)) > GUARDIAN_SESSION_INACTIVITY) {
        return false;
    }
    if (($now - (int) ($_SESSION['login_at'] ?? 0)) > GUARDIAN_SESSION_ABSOLUTE) {
        return false;
    }
    if (($_SESSION['token_fp'] ?? null) !== guardian_token_fingerprint($tokenStore)) {
        return false; // token rotated → old session no longer valid
    }
    $_SESSION['last_seen'] = $now;
    return true;
}

function guardian_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], true);
    }
    session_destroy();
}

function guardian_json(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = (string) ($_POST['action'] ?? ($_GET['action'] ?? ''));

// ── Login (rate-limited, generic failure, no prefix hint) ─────────────────────
if ($method === 'POST' && $action === 'login') {
    $ip = guardian_client_ip();
    $limit = $rateLimiter->check($ip);
    if ($limit['locked']) {
        $panelConfig->audit('panel.login_locked', ['retry_after' => $limit['retryAfter']]);
        guardian_json(['success' => false, 'error' => 'Too many attempts. Try again later.'], 429);
    }
    if (!guardian_check_csrf()) {
        guardian_json(['success' => false, 'error' => 'Your session expired. Reload and try again.'], 400);
    }
    $presented = (string) ($_POST['token'] ?? '');
    if ($tokenStore->verify($presented)) {
        $rateLimiter->registerSuccess($ip);
        session_regenerate_id(true);      // prevent fixation
        $_SESSION['auth'] = true;
        $_SESSION['login_at'] = time();
        $_SESSION['last_seen'] = time();
        $_SESSION['token_fp'] = guardian_token_fingerprint($tokenStore);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $panelConfig->audit('panel.login_ok');
        guardian_json(['success' => true]);
    }
    $rateLimiter->registerFailure($ip);
    $panelConfig->audit('panel.login_failed');
    // Generic message; never reveals whether the token prefix was correct.
    guardian_json(['success' => false, 'error' => 'Authentication failed.'], 401);
}

$authenticated = guardian_is_authenticated($tokenStore);

// ── Authenticated JSON actions (CSRF-protected, POST for state changes) ───────
if ($action !== '' && $action !== 'login') {
    if (!$authenticated) {
        guardian_json(['success' => false, 'error' => 'Not authenticated.'], 401);
    }
    if ($method !== 'POST' || !guardian_check_csrf()) {
        guardian_json(['success' => false, 'error' => 'Invalid request.'], 400);
    }

    if ($action === 'logout') {
        $panelConfig->audit('panel.logout');
        guardian_logout();
        guardian_json(['success' => true]);
    }

    if ($action === 'list') {
        $backups = [];
        foreach ($kernel->catalog()->recoverableList() as $manifest) {
            $backups[] = [
                'id' => (string) ($manifest['id'] ?? ''),
                'type' => (string) ($manifest['type'] ?? ''),
                'created_at' => (string) ($manifest['created_at'] ?? ''),
                'components' => array_keys(array_filter((array) ($manifest['components'] ?? []))),
                'size' => (int) ($manifest['archive_size'] ?? 0),
            ];
        }
        guardian_json(['success' => true, 'backups' => $backups]);
    }

    $body = json_decode((string) file_get_contents('php://input'), true);
    $body = is_array($body) ? $body : [];

    if ($action === 'interrupted') {
        guardian_json(['success' => true, 'incomplete' => $kernel->journal()->findIncomplete()]);
    }

    if ($action === 'rollback-interrupted') {
        $jobId = (string) ($body['jobId'] ?? '');
        $panelConfig->audit('recovery.rollback_interrupted', ['job' => $jobId]);
        try {
            $result = $kernel->restoreService()->rollbackInterrupted($jobId);
        } catch (\Throwable $e) {
            guardian_json(['success' => false, 'error' => $e->getMessage()], 500);
        }
        guardian_json(['success' => true, 'rolledBack' => $result->rolledBack, 'log' => $result->log]);
    }

    if ($action === 'preflight') {
        $backupId = (string) ($body['backupId'] ?? '');
        $components = is_array($body['components'] ?? null) ? $body['components'] : [];
        unset($components['vendor']); // vendor is controlled by strategy only
        guardian_json(['success' => true, 'preflight' => $kernel->preflight()->run($backupId, $components)]);
    }

    if ($action === 'dryrun') {
        $backupId = (string) ($body['backupId'] ?? '');
        $components = is_array($body['components'] ?? null) ? $body['components'] : [];
        unset($components['vendor']);
        $vendorStrategy = (string) ($body['vendorStrategy'] ?? 'rebuild');
        try {
            $result = $kernel->dryRun()->run($backupId, $components, $vendorStrategy);
        } catch (\Throwable $e) {
            $kernel->dryRun()->invalidate();
            guardian_json(['success' => false, 'error' => $e->getMessage()], 400);
        }
        guardian_json(['success' => true, 'dryRun' => $result]);
    }

    if ($action === 'run') {
        $backupId = (string) ($body['backupId'] ?? '');
        $components = is_array($body['components'] ?? null) ? $body['components'] : [];
        $confirm = ($body['confirm'] ?? false) === true;
        $phrase = strtoupper(trim((string) ($body['phrase'] ?? '')));
        $vendorStrategy = (string) ($body['vendorStrategy'] ?? 'rebuild');
        $vendorPhrase = strtoupper(trim((string) ($body['vendorPhrase'] ?? '')));

        if (!$confirm || $phrase !== 'RECOVER') {
            guardian_json(['success' => false, 'error' => 'Type RECOVER and tick the box to confirm.'], 400);
        }
        // Direct vendor restore is disabled; vendor is driven only by the strategy.
        if (($components['vendor'] ?? null) === true) {
            $panelConfig->audit('recovery.vendor_flag_rejected', ['backup' => $backupId]);
            guardian_json(['success' => false, 'error' => 'Direct vendor restore is disabled for safety. Choose a vendor strategy.'], 400);
        }
        if ($vendorStrategy === 'archived' && $vendorPhrase !== 'RESTORE VENDOR') {
            guardian_json(['success' => false, 'error' => 'Archived vendor restore requires typing RESTORE VENDOR.'], 400);
        }
        $fingerprint = \Vtinnovations\GuardianTypo3\Application\Recovery\RecoveryDryRun::fingerprint(
            $backupId,
            $components,
            \Vtinnovations\GuardianTypo3\Domain\Recovery\VendorRestoreStrategy::fromString($vendorStrategy)->value,
        );
        if (!$kernel->dryRun()->matchesLastDryRun($fingerprint)) {
            guardian_json(['success' => false, 'error' => 'Run a successful dry run for this exact selection first.'], 409);
        }

        @set_time_limit(0);
        @ignore_user_abort(true);
        $panelConfig->audit('recovery.started', ['backup' => $backupId, 'vendor' => $vendorStrategy]);
        try {
            $result = $kernel->restoreService()->restore($backupId, $components, true, true, $vendorStrategy);
        } catch (\Throwable $e) {
            $panelConfig->audit('recovery.failed', ['backup' => $backupId]);
            guardian_json(['success' => false, 'error' => $e->getMessage()], 500);
        }
        $panelConfig->audit('recovery.completed', ['backup' => $backupId]);
        guardian_json([
            'success' => true,
            'result' => [
                'restored' => $result->restoredComponents,
                'safetySnapshotId' => $result->safetySnapshotId,
                'rolledBack' => $result->rolledBack,
                'log' => $result->log,
            ],
        ]);
    }

    guardian_json(['success' => false, 'error' => 'Unknown action.'], 400);
}

// ── Render the shell (login screen or the recovery app) ───────────────────────
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'self'");
}

$csrf = guardian_csrf();
$self = htmlspecialchars(basename(__FILE__), ENT_QUOTES);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Guardian Recovery</title>
<style>
:root{--bg:#0d1117;--panel:#161b22;--line:#30363d;--txt:#c9d1d9;--muted:#8b949e;--accent:#f47c00;--ok:#3fb950;--warn:#d29922;--err:#f85149}
@media (prefers-color-scheme:light){:root{--bg:#f6f8fa;--panel:#fff;--line:#d0d7de;--txt:#1f2328;--muted:#656d76}}
*{box-sizing:border-box}
body{margin:0;font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--txt)}
.wrap{max-width:820px;margin:0 auto;padding:2rem 1rem}
.brand{display:flex;align-items:center;gap:.6rem;margin-bottom:1.2rem}
.brand svg{width:40px;height:40px}
.brand h1{font-size:1.25rem;margin:0;font-weight:700}
.brand span{color:var(--accent)}
.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:1.2rem;margin-bottom:1rem}
label{display:block;font-size:.85rem;color:var(--muted);margin:.4rem 0 .2rem}
input[type=text],input[type=password]{width:100%;padding:.6rem .7rem;background:var(--bg);border:1px solid var(--line);border-radius:8px;color:var(--txt);font:inherit}
button{background:var(--accent);color:#fff;border:0;border-radius:8px;padding:.6rem 1rem;font:inherit;font-weight:600;cursor:pointer}
button.secondary{background:transparent;border:1px solid var(--line);color:var(--txt)}
button:disabled{opacity:.5;cursor:not-allowed}
.row{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}
.muted{color:var(--muted);font-size:.85rem}
.list{list-style:none;margin:0;padding:0}
.list li{border:1px solid var(--line);border-radius:8px;padding:.6rem .7rem;margin-bottom:.5rem;cursor:pointer}
.list li.sel{border-color:var(--accent);outline:1px solid var(--accent)}
.chk{display:flex;align-items:center;gap:.4rem;margin:.2rem 0}
.check{font-size:.88rem;margin:.2rem 0;padding:.35rem .5rem;border-radius:6px}
.check.ok{color:var(--ok)} .check.warning{color:var(--warn)} .check.error{color:var(--err)}
pre{background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:.7rem;overflow:auto;max-height:320px;font-size:.82rem;white-space:pre-wrap}
.err{color:var(--err)} .ok{color:var(--ok)}
.hide{display:none}
.topbar{display:flex;justify-content:space-between;align-items:center}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <svg viewBox="0 0 64 64" aria-hidden="true"><path d="M32 4 12 12v16c0 12.4 8.2 23.9 20 28 11.8-4.1 20-15.6 20-28V12L32 4Z" fill="none" stroke="#f47c00" stroke-width="4" stroke-linejoin="round"/><path d="M23 32.5 29.5 39 42 25.5" fill="none" stroke="#f47c00" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <h1>Guardian <span>Recovery</span></h1>
  </div>

<?php if (!$authenticated): ?>
  <div class="card" id="login">
    <p class="muted">Enter the recovery access token you saved when you generated it in the Guardian backend.</p>
    <label for="token">Recovery access token</label>
    <input type="password" id="token" autocomplete="off" autofocus>
    <div class="row" style="margin-top:.8rem"><button id="loginBtn">Unlock</button> <span id="loginMsg" class="err"></span></div>
  </div>
<?php else: ?>
  <div class="card topbar">
    <span class="muted">Authenticated. Sessions expire after 15 min idle / 1 h.</span>
    <button class="secondary" id="logoutBtn">Log out</button>
  </div>

  <div class="card" id="step-list">
    <h3 style="margin-top:0">1 · Choose a backup</h3>
    <ul class="list" id="backups"><li class="muted">Loading…</li></ul>
  </div>

  <div class="card hide" id="interrupted" style="border:1px solid var(--err)"></div>

  <div class="card hide" id="step-components">
    <h3 style="margin-top:0">2 · Components to restore</h3>
    <div id="components"></div>
    <label style="display:block;margin-top:.7rem">Vendor strategy</label>
    <select id="vstrat" style="width:100%;padding:.5rem;background:var(--bg);border:1px solid var(--line);border-radius:8px;color:var(--txt)">
      <option value="rebuild">Rebuild vendor from composer.lock — recommended</option>
      <option value="skip">Do not touch vendor</option>
      <option value="archived">Restore archived vendor — advanced, high risk</option>
    </select>
    <div id="vendorPhraseWrap" class="hide" style="margin-top:.5rem">
      <label for="vendorPhrase" class="err">Advanced: type <b>RESTORE VENDOR</b> to allow the archived vendor restore</label>
      <input type="text" id="vendorPhrase" autocomplete="off" placeholder="RESTORE VENDOR">
    </div>
    <div class="row" style="margin-top:.6rem"><button id="preflightBtn">Run preflight</button></div>
  </div>

  <div class="card hide" id="step-preflight">
    <h3 style="margin-top:0">3 · Preflight &amp; mandatory dry run</h3>
    <div id="checks"></div>
    <div class="row" style="margin-top:.6rem"><button id="dryRunBtn">Run dry run (required)</button> <span id="dryMsg" class="muted"></span></div>
    <div id="dryChecks" style="margin-top:.5rem"></div>
    <label for="phrase" style="margin-top:.6rem;display:block">Type <b>RECOVER</b> to confirm</label>
    <input type="text" id="phrase" autocomplete="off" placeholder="RECOVER">
    <label class="chk"><input type="checkbox" id="confirm"> I understand this will overwrite the selected components.</label>
    <div class="row" style="margin-top:.6rem"><button id="runBtn" disabled>Start recovery</button> <span id="runMsg" class="err"></span></div>
  </div>

  <div class="card hide" id="step-log">
    <h3 style="margin-top:0">4 · Recovery log</h3>
    <div id="result" class="muted"></div>
    <pre id="log"></pre>
  </div>
<?php endif; ?>

  <p class="muted" style="text-align:center">Guardian for TYPO3 · V&amp;T Innovations</p>
</div>

<script>
(function(){
  var CSRF = <?= json_encode($csrf) ?>;
  var SELF = <?= json_encode(basename(__FILE__)) ?>;
  function post(action, body){
    var opts={method:'POST',headers:{'X-Guardian-Csrf':CSRF}};
    if(action==='login'){
      var fd=new URLSearchParams();fd.set('action','login');fd.set('csrf',CSRF);fd.set('token',body.token);
      opts.body=fd; opts.headers['Content-Type']='application/x-www-form-urlencoded';
    } else {
      opts.headers['Content-Type']='application/json';
      opts.body=JSON.stringify(body||{});
    }
    return fetch(SELF+'?action='+encodeURIComponent(action),opts).then(function(r){return r.json().catch(function(){return{success:false,error:'Server error.'}})});
  }
  function el(id){return document.getElementById(id)}

  var loginBtn=el('loginBtn');
  if(loginBtn){
    function doLogin(){
      el('loginMsg').textContent='';
      post('login',{token:el('token').value}).then(function(r){
        if(r.success){location.reload()} else {el('loginMsg').textContent=r.error||'Failed.'}
      });
    }
    loginBtn.addEventListener('click',doLogin);
    el('token').addEventListener('keydown',function(e){if(e.key==='Enter')doLogin()});
    return;
  }

  var state={backup:null,components:{},dryOk:false};
  el('logoutBtn').addEventListener('click',function(){post('logout',{}).then(function(){location.reload()})});

  // Detect an interrupted recovery and offer a safe rollback before anything else.
  post('interrupted',{}).then(function(r){
    if(r.success && r.incomplete && r.incomplete.length){
      var t=r.incomplete[0];
      var box=el('interrupted');box.classList.remove('hide');
      box.innerHTML='<h3 style="margin-top:0" class="err">⚠ Interrupted recovery detected</h3>'
        +'<p class="muted">Job '+(t.job_id||'')+' stopped at step "'+(t.step||'?')+'". A new recovery is blocked until this is rolled back.</p>'
        +'<button id="rbBtn">Roll back the interrupted recovery</button> <span id="rbMsg" class="muted"></span>';
      el('rbBtn').addEventListener('click',function(){
        el('rbBtn').disabled=true;el('rbMsg').textContent='Rolling back…';
        post('rollback-interrupted',{jobId:t.job_id}).then(function(x){
          el('rbMsg').textContent=x.success?('Rollback '+(x.rolledBack?'succeeded':'incomplete')):(x.error||'Failed');
        });
      });
    }
  });

  el('vstrat').addEventListener('change',function(){
    el('vendorPhraseWrap').classList.toggle('hide', el('vstrat').value!=='archived');
    state.dryOk=false; updateRun(false);
  });

  post('list',{}).then(function(r){
    var ul=el('backups');ul.innerHTML='';
    if(!r.success||!r.backups.length){ul.innerHTML='<li class="muted">No recoverable backups found.</li>';return}
    r.backups.forEach(function(b){
      var li=document.createElement('li');
      li.textContent=b.created_at+'  ·  '+b.type+'  ·  '+(b.components||[]).join(', ');
      li.addEventListener('click',function(){
        Array.prototype.forEach.call(ul.children,function(c){c.classList.remove('sel')});
        li.classList.add('sel');state.backup=b;renderComponents(b);
      });
      ul.appendChild(li);
    });
  });

  function renderComponents(b){
    var box=el('components');box.innerHTML='';state.components={};state.dryOk=false;
    // vendor is NEVER a component checkbox; it is controlled by the strategy.
    (b.components||[]).filter(function(c){return c!=='vendor'}).forEach(function(c){
      var id='c_'+c;
      var lbl=document.createElement('label');lbl.className='chk';
      var cb=document.createElement('input');cb.type='checkbox';cb.checked=true;cb.id=id;
      cb.addEventListener('change',function(){state.components[c]=cb.checked;state.dryOk=false;updateRun(false)});
      state.components[c]=true;
      lbl.appendChild(cb);lbl.appendChild(document.createTextNode(' '+c));
      box.appendChild(lbl);
    });
    el('step-components').classList.remove('hide');
    el('step-preflight').classList.add('hide');
    el('step-log').classList.add('hide');
  }

  function payload(extra){
    var p={backupId:state.backup.id,components:state.components,vendorStrategy:el('vstrat').value};
    for(var k in (extra||{})){p[k]=extra[k]}
    return p;
  }

  el('preflightBtn').addEventListener('click',function(){
    if(!state.backup)return;
    post('preflight',payload()).then(function(r){
      var box=el('checks');box.innerHTML='';
      if(!r.success){box.innerHTML='<div class="check error">Preflight failed.</div>';return}
      (r.preflight.checks||[]).forEach(function(ch){
        var d=document.createElement('div');d.className='check '+ch.severity;d.textContent='• '+ch.message;box.appendChild(d);
      });
      el('step-preflight').classList.remove('hide');
      state.dryOk=false;updateRun(false);
    });
  });

  el('dryRunBtn').addEventListener('click',function(){
    el('dryRunBtn').disabled=true;el('dryMsg').textContent='Running dry run…';
    post('dryrun',payload()).then(function(r){
      el('dryRunBtn').disabled=false;
      var box=el('dryChecks');box.innerHTML='';
      if(!r.success){el('dryMsg').textContent=r.error||'Dry run failed';state.dryOk=false;updateRun(false);return}
      (r.dryRun.checks||[]).forEach(function(ch){
        var d=document.createElement('div');d.className='check '+ch.severity;d.textContent='• '+ch.message;box.appendChild(d);
      });
      state.dryOk=!!r.dryRun.ok;
      el('dryMsg').textContent=state.dryOk?'Dry run passed — you may recover.':'Dry run has blocking errors.';
      updateRun(true);
    });
  });

  function updateRun(){
    var vphraseOk=el('vstrat').value!=='archived' || el('vendorPhrase').value.trim().toUpperCase()==='RESTORE VENDOR';
    var ok=state.dryOk && el('phrase').value.trim().toUpperCase()==='RECOVER' && el('confirm').checked && vphraseOk;
    el('runBtn').disabled=!ok;
  }
  ['input','change'].forEach(function(ev){
    el('phrase').addEventListener(ev,updateRun);
    el('confirm').addEventListener(ev,updateRun);
    el('vendorPhrase').addEventListener(ev,updateRun);
  });

  el('runBtn').addEventListener('click',function(){
    el('runBtn').disabled=true;el('runMsg').textContent='';
    el('step-log').classList.remove('hide');el('log').textContent='Working…';
    post('run',payload({confirm:el('confirm').checked,phrase:el('phrase').value,vendorPhrase:el('vendorPhrase').value})).then(function(r){
      if(!r.success){el('result').innerHTML='<span class="err">'+(r.error||'Recovery failed.')+'</span>';el('log').textContent='';return}
      var res=r.result;
      el('result').innerHTML='<span class="ok">Recovery completed.</span> Restored: '+(res.restored||[]).join(', ')+(res.safetySnapshotId?(' · snapshot '+res.safetySnapshotId):'');
      el('log').textContent=(res.log||[]).join('\n');
    });
  });
})();
</script>
</body>
</html>
