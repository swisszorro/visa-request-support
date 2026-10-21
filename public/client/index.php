<?php

declare(strict_types=1);

/**
 * Client form for the visa request service. Same input as public/test/index.html, but the
 * connection data (service URL, API key) live in config/client.php and are used server-side
 * by submit.php - the browser never sees them.
 */

use BWC\Visa\ClientPortal;

require __DIR__ . '/../../vendor/autoload.php';

$nonce = base64_encode(random_bytes(16));
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; "
    . "connect-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fail(int $status, string $title, string $text, string $nonce): never
{
    http_response_code($status);
    echo '<!DOCTYPE html><meta charset="utf-8"><title>' . h($title) . '</title><style nonce="' . $nonce . '">body{font-family:system-ui;max-width:560px;margin:15vh auto;padding:0 18px;color:#222}</style>'
        . '<h1>' . h($title) . '</h1><p>' . h($text) . '</p>';
    exit;
}

try {
    $cfg = ClientPortal::load();
} catch (\RuntimeException $e) {
    fail(503, 'Form unavailable', $e->getMessage(), $nonce);
}

$auth = ClientPortal::authenticate($cfg, ClientPortal::authorizationHeader());
if ($auth === 'unconfigured') {
    fail(503, 'Access protection missing', 'No user (users) is configured in config/client.php. For security reasons the form stays locked.', $nonce);
}
if ($auth !== 'ok') {
    header('WWW-Authenticate: Basic realm="BWC Visa Client", charset="UTF-8"');
    fail(401, 'Login required', 'Please sign in with your username and password.', $nonce);
}

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
$csrf = ClientPortal::csrfToken();
session_write_close();

$d = static fn (string $k): string => h($cfg['defaults'][$k] ?? '');
$host = (string) parse_url($cfg['api_url'], PHP_URL_HOST);
// Absolute path of submit.php, independent of how the page was opened (/client, /client/, /client/index.php,
// or below a sub-directory): a relative 'submit.php' would resolve to /submit.php when the trailing slash is missing.
$submitUrl = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/client/index.php'))), '/') . '/submit.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title>BWC Visa Request</title>
<style nonce="<?= $nonce ?>">
  :root { --bg:#0f172a; --card:#1e293b; --line:#334155; --txt:#e2e8f0; --mut:#94a3b8;
          --ok:#22c55e; --err:#ef4444; --warn:#f59e0b; --accent:#38bdf8; }
  * { box-sizing: border-box; }
  body { margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: var(--bg); color: var(--txt); }
  .wrap { max-width: 920px; margin: 0 auto; padding: 28px 18px 80px; }
  h1 { font-size: 22px; margin: 0 0 4px; }
  p.sub { color: var(--mut); margin: 0 0 24px; }
  .card { background: var(--card); border:1px solid var(--line); border-radius: 12px; padding: 20px; margin-bottom: 18px; }
  h2 { font-size: 14px; text-transform: uppercase; letter-spacing:.06em; color: var(--mut); margin: 0 0 14px; }
  label { display:block; font-size: 13px; margin: 10px 0 4px; color: var(--mut); }
  input[type=text], input[type=file], input[type=email] { width:100%; padding:9px 11px; border-radius:8px; border:1px solid var(--line); background:#0b1220; color:var(--txt); font-size:14px; }
  .grid { display:grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
  @media (max-width: 640px) { .grid { grid-template-columns: 1fr; } }
  .check { display:flex; gap:8px; align-items:center; margin-top:14px; color:var(--txt); font-size:14px; }
  button { background: var(--accent); color:#04293b; border:0; padding:11px 20px; border-radius:9px; font-weight:600; font-size:15px; cursor:pointer; margin-top:16px; }
  button:disabled { opacity:.5; cursor: progress; }
  .pill { display:inline-block; padding:2px 9px; border-radius:999px; font-size:12px; font-weight:600; }
  .pill.ok { background: rgba(34,197,94,.15); color: var(--ok); }
  .pill.err { background: rgba(239,68,68,.15); color: var(--err); }
  .pill.warn { background: rgba(245,158,11,.15); color: var(--warn); }
  table { width:100%; border-collapse: collapse; font-size:13px; margin-top:8px; }
  th, td { text-align:left; padding:7px 8px; border-bottom:1px solid var(--line); vertical-align: top; }
  th { color: var(--mut); font-weight:600; }
  .hint { font-size:12px; color: var(--mut); margin-top:4px; }
  a.dl { color: var(--accent); font-weight:600; }
  .summary span { margin-right:18px; }
  .box { background:#0b1220; border:1px solid var(--line); border-radius:8px; padding:12px; white-space:pre-wrap; font-size:13px; }
  .hidden { display:none; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Berne World Cup · Visa Request</h1>
  <p class="sub">Upload the Excel list and the passport copies – the invitation letter is created automatically. Service: <code><?= h($host) ?></code></p>

  <form id="form" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />

    <div class="card">
      <h2>Requesting national federation</h2>
      <div class="grid">
        <div><label for="name">Last name</label><input type="text" id="name" name="name" required value="<?= $d('name') ?>" /></div>
        <div><label for="firstname">First name</label><input type="text" id="firstname" name="firstname" required value="<?= $d('firstname') ?>" /></div>
        <div><label for="address">Address</label><input type="text" id="address" name="address" required value="<?= $d('address') ?>" /></div>
        <div><label for="zip">ZIP / postal code</label><input type="text" id="zip" name="zip" required value="<?= $d('zip') ?>" /></div>
        <div><label for="city">City</label><input type="text" id="city" name="city" required value="<?= $d('city') ?>" /></div>
        <div><label for="country">Country</label><input type="text" id="country" name="country" required value="<?= $d('country') ?>" /></div>
        <div><label for="federation">National federation</label><input type="text" id="federation" name="federation" required value="<?= $d('federation') ?>" /></div>
        <div><label for="email">E-mail address</label><input type="email" id="email" name="email" value="<?= $d('email') ?>" /></div>
      </div>
      <label class="check"><input type="checkbox" id="send_email" name="send_email" value="1" /> Also send the invitation letter by e-mail to this address</label>
    </div>

    <div class="card">
      <h2>Files</h2>
      <label for="excel">Excel file (table “Applicants”)</label>
      <input type="file" id="excel" name="excel" accept=".xlsx" required />
      <label for="images">Passport copies (max. <?= (int) $cfg['max_images'] ?> · png, jpg, gif, pdf)</label>
      <input type="file" id="images" name="images[]" accept=".png,.jpg,.jpeg,.gif,.pdf" multiple required />
      <div class="hint" id="fileHint"></div>
    </div>

    <button type="submit" id="submit">Submit request</button>
    <span id="status" style="margin-left:14px;"></span>
  </form>

  <div class="card hidden" id="resultCard">
    <h2>Result</h2>
    <div class="summary" id="summary"></div>
    <div id="notice" style="margin:12px 0;"></div>
    <div id="pdfArea" style="margin:12px 0;"></div>

    <h2 style="margin-top:20px;">Accepted</h2>
    <table id="okTable"><thead><tr><th>Name</th><th>Date of birth</th></tr></thead><tbody></tbody></table>

    <h2 style="margin-top:20px;">Not accepted</h2>
    <table id="failTable"><thead><tr><th>Name</th><th>Date of birth</th><th>Reason</th></tr></thead><tbody></tbody></table>

    <div id="diagArea" class="hidden">
      <h2 style="margin-top:20px;">Problems with files</h2>
      <table id="diagTable"><thead><tr><th>File</th><th>Status</th><th>Note</th></tr></thead><tbody></tbody></table>
    </div>

    <div id="mailArea" class="hidden">
      <h2 style="margin-top:20px;">E-mail sent to the applicant</h2>
      <div id="mailState" style="margin-bottom:8px;"></div>
      <div class="box" id="mailBody"></div>
    </div>
  </div>
</div>

<script nonce="<?= $nonce ?>">
const $ = id => document.getElementById(id);
const MAX_IMAGES = <?= (int) $cfg['max_images'] ?>;
const SUBMIT_URL = <?= json_encode($submitUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const tbody = id => $(id).querySelector('tbody');

$('images').addEventListener('change', e => {
  const n = e.target.files.length;
  $('fileHint').textContent = n ? `${n} file(s) selected` + (n > MAX_IMAGES ? ` — too many! Max. ${MAX_IMAGES}.` : '') : '';
});

$('form').addEventListener('submit', async (e) => {
  e.preventDefault();
  if ($('images').files.length > MAX_IMAGES) { alert(`Too many files (max. ${MAX_IMAGES}).`); return; }
  $('submit').disabled = true;
  $('status').textContent = 'Processing … (the image analysis can take a few minutes, please do not close this page)';
  try {
    const res = await fetch(SUBMIT_URL, { method: 'POST', body: new FormData($('form')), credentials: 'same-origin' });
    let data;
    try { data = await res.json(); } catch { data = { ok: false, error: `Unexpected response from ${SUBMIT_URL} (HTTP ${res.status}).` }; }
    render(data);
  } catch (err) {
    render({ ok: false, error: 'Connection failed: ' + err.message });
  } finally {
    $('submit').disabled = false;
    $('status').textContent = '';
  }
});

function b64toBlob(b64, type) {
  const bin = atob(b64); const a = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) a[i] = bin.charCodeAt(i);
  return new Blob([a], { type });
}

function render(data) {
  $('resultCard').classList.remove('hidden');
  for (const id of ['okTable', 'failTable', 'diagTable']) tbody(id).innerHTML = '';
  for (const id of ['diagArea', 'mailArea']) $(id).classList.add('hidden');
  $('pdfArea').innerHTML = ''; $('notice').innerHTML = '';

  if (!data.ok) {
    $('summary').innerHTML = `<span class="pill err">Error</span> ${esc(data.error || 'Unknown error')}`;
    if (data.request_id) $('notice').innerHTML = `<span class="hint">Reference: ${esc(data.request_id)}</span>`;
    return;
  }

  const s = data.summary || {};
  $('summary').innerHTML =
    `<span>Applicants: <b>${esc(s.applicants_total ?? '–')}</b></span>` +
    `<span>Passports detected: <b>${esc(s.passports_detected ?? '–')}</b></span>` +
    `<span class="pill ok">${esc(s.successful ?? 0)} accepted</span>` +
    `<span class="pill err">${esc(s.failed ?? 0)} not accepted</span>` +
    ((s.needs_review ?? 0) > 0 ? `<span class="pill warn">${esc(s.needs_review)} need manual review</span>` : '');

  if (data.incomplete) {
    $('notice').innerHTML = '<span class="pill warn">Incomplete</span> Some files could not be read for technical reasons (see below). Please check and resubmit if necessary.';
  }

  if (data.document_base64) {
    const url = URL.createObjectURL(b64toBlob(data.document_base64, data.document_mime || 'application/octet-stream'));
    const label = (data.document_format || 'pdf').toUpperCase();
    $('pdfArea').innerHTML = `<a class="dl" href="${url}" download="${esc(data.document_filename || 'invitation')}">⬇︎ Download invitation letter (${esc(label)})</a>`;
  }

  tbody('okTable').innerHTML = (data.successful || []).map(r => `<tr><td>${esc(r.name)}</td><td>${esc(r.date_of_birth)}</td></tr>`).join('') || '<tr><td colspan="2">–</td></tr>';

  tbody('failTable').innerHTML = (data.failed || []).map(r => {
    const tag = r.status === 'review' ? '<span class="pill warn">Review needed</span> ' : '';
    const detail = (r.mismatches && r.mismatches.length)
      ? '<ul style="margin:0;padding-left:18px;">' + r.mismatches.map(m =>
          `<li><b>${esc(m.field)}</b>: Excel <code>${esc(m.expected || '–')}</code> ↔ Pass <code>${esc(m.found || '–')}</code></li>`).join('') + '</ul>'
      : esc(r.reason || '');
    return `<tr><td>${esc(r.name)}</td><td>${esc(r.date_of_birth)}</td><td>${tag}${detail}</td></tr>`;
  }).join('') || '<tr><td colspan="3">–</td></tr>';

  const diags = data.file_diagnostics || [];
  if (diags.length) {
    $('diagArea').classList.remove('hidden');
    tbody('diagTable').innerHTML = diags.map(d => `<tr><td>${esc(d.source)}${d.page > 1 ? ' (p. ' + esc(d.page) + ')' : ''}</td><td>${esc(d.status)}</td><td>${esc(d.message)}</td></tr>`).join('');
  }

  const m = data.email;
  if (m) {
    $('mailArea').classList.remove('hidden');
    $('mailState').innerHTML = m.sent
      ? `<span class="pill ok">sent</span> to ${esc((m.to || []).join(', '))}${m.attached ? ' · attachment: ' + esc(m.attachment?.filename || '') : ' · no attachment'}`
      : `<span class="pill warn">not sent</span> ${esc(m.error || '')}`;
    $('mailBody').textContent = `Subject: ${m.subject || ''}\n\n${m.html ? String(m.body || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() : (m.body || '')}`;
  }
}
</script>
</body>
</html>
