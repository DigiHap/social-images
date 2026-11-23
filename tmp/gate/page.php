<?php
/** @var string $mode setup|login|add  @var string $back */
$titles = [
    'setup' => ['Passkey einrichten', 'Lege jetzt Deinen Passkey an. Er wird über den iCloud-Schlüsselbund automatisch auf Mac, iPhone und iPad übernommen.', 'Passkey einrichten'],
    'login' => ['Anmelden', 'Bitte mit Deinem Passkey bestätigen (Face ID, Touch ID oder Ledger).', 'Mit Passkey anmelden'],
    'add'   => ['Weiteren Passkey hinzufügen', 'Zum Beispiel für ein Gerät außerhalb des iCloud-Schlüsselbunds oder einen Hardware-Schlüssel.', 'Passkey hinzufügen'],
];
[$h, $p, $btn] = $titles[$mode];
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'unsafe-inline'; frame-ancestors 'none'");
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="apple-mobile-web-app-capable" content="yes">
<title><?= htmlspecialchars($h) ?> · Mail-Zentrale</title>
<style>
  :root { color-scheme: light dark; --bg:#f4f6f9; --card:#fff; --ink:#1d2433; --mute:#5d6778; --accent:#2b5fd9; --err:#c62828; }
  @media (prefers-color-scheme: dark) { :root { --bg:#12151b; --card:#1c2129; --ink:#e8ecf2; --mute:#9aa4b4; --accent:#6c9bff; } }
  * { box-sizing: border-box; }
  body { margin:0; min-height:100vh; display:grid; place-items:center; background:var(--bg); color:var(--ink);
         font:16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding:20px; }
  main { width:100%; max-width:380px; background:var(--card); border-radius:16px; padding:32px 28px;
         box-shadow:0 8px 30px rgba(0,0,0,.08); text-align:center; }
  .icon { width:56px; height:56px; margin:0 auto 16px; border-radius:14px; background:var(--accent); display:grid; place-items:center; }
  h1 { font-size:1.3rem; margin:0 0 8px; }
  p { color:var(--mute); margin:0 0 24px; font-size:.95rem; }
  button { width:100%; border:0; border-radius:10px; padding:14px; font-size:1rem; font-weight:600; color:#fff; background:var(--accent); cursor:pointer; }
  button:disabled { opacity:.6; cursor:default; }
  input { width:100%; padding:12px; border-radius:10px; border:1px solid #c9d1dd; margin:0 0 12px; font-size:1rem; background:transparent; color:inherit; }
  #msg { min-height:1.5em; margin-top:16px; font-size:.9rem; color:var(--err); }
  a { color:var(--accent); font-size:.85rem; }
</style>
</head>
<body>
<main>
  <div class="icon" aria-hidden="true">
    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
  </div>
  <h1><?= htmlspecialchars($h) ?></h1>
  <p><?= htmlspecialchars($p) ?></p>
  <?php if ($mode !== 'login'): ?>
    <input id="name" placeholder="Name, z. B. iCloud-Schlüsselbund" maxlength="60" autocomplete="off">
  <?php endif; ?>
  <button id="go"><?= htmlspecialchars($btn) ?></button>
  <div id="msg" role="status"></div>
  <?php if ($mode === 'add'): ?><p style="margin:16px 0 0"><a href="/">Zurück zur Mail-Zentrale</a></p><?php endif; ?>
</main>
<script>
const MODE = <?= json_encode($mode) ?>, BACK = <?= json_encode($back) ?>;
const b64u = s => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), c => c.charCodeAt(0));
const b64 = buf => btoa(String.fromCharCode(...new Uint8Array(buf)));
const msg = t => { document.getElementById('msg').textContent = t || ''; };
async function post(url, body) {
  const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}), credentials: 'same-origin' });
  const j = await r.json().catch(() => ({ error: 'Unerwartete Antwort' }));
  if (!r.ok || j.error) throw new Error(j.error || ('Fehler ' + r.status));
  return j;
}
async function register() {
  const o = await post('/__pk/reg-options');
  const pk = o.publicKey;
  pk.challenge = b64u(pk.challenge);
  pk.user.id = b64u(pk.user.id);
  (pk.excludeCredentials || []).forEach(c => c.id = b64u(c.id));
  const cred = await navigator.credentials.create({ publicKey: pk });
  await post('/__pk/reg-verify', {
    clientDataJSON: b64(cred.response.clientDataJSON),
    attestationObject: b64(cred.response.attestationObject),
    name: (document.getElementById('name') || {}).value || ''
  });
}
async function login() {
  const o = await post('/__pk/login-options');
  const pk = o.publicKey;
  pk.challenge = b64u(pk.challenge);
  (pk.allowCredentials || []).forEach(c => c.id = b64u(c.id));
  const cred = await navigator.credentials.get({ publicKey: pk });
  await post('/__pk/login-verify', {
    id: b64(cred.rawId),
    clientDataJSON: b64(cred.response.clientDataJSON),
    authenticatorData: b64(cred.response.authenticatorData),
    signature: b64(cred.response.signature)
  });
}
document.getElementById('go').addEventListener('click', async e => {
  const btn = e.currentTarget; btn.disabled = true; msg('');
  try {
    if (!window.PublicKeyCredential) throw new Error('Dieser Browser unterstützt keine Passkeys.');
    if (MODE === 'login') await login(); else await register();
    msg(''); location.replace(MODE === 'add' ? '/' : BACK);
  } catch (err) {
    msg(err.name === 'NotAllowedError' ? 'Abgebrochen oder Zeit abgelaufen. Bitte erneut versuchen.' : err.message);
    btn.disabled = false;
  }
});
</script>
</body>
</html>
