<?php
/**
 * Passkey-Schutzschicht fuer die Mail-Zentrale (pst.engelgroup.us)
 * Wird per auto_prepend_file vor jedem PHP-Aufruf geladen.
 * Ohne gueltigen Passkey-Cookie kommt niemand bis zu FreeScout.
 */
if (PHP_SAPI === 'cli') { return; }

define('PKG_DIR', __DIR__);
define('PKG_RP_ID', 'pst.engelgroup.us');
define('PKG_RP_NAME', 'Mail-Zentrale');
define('PKG_COOKIE', 'pkg_auth');
define('PKG_DAYS', 30);

function pkg_secret() {
    return trim(file_get_contents(PKG_DIR . '/data/secret'));
}
function pkg_creds() {
    $f = PKG_DIR . '/data/creds.json';
    if (!is_file($f)) { return []; }
    $j = json_decode(file_get_contents($f), true);
    return is_array($j) ? $j : [];
}
function pkg_save_creds(array $c) {
    $f = PKG_DIR . '/data/creds.json';
    file_put_contents($f . '.tmp', json_encode($c, JSON_PRETTY_PRINT), LOCK_EX);
    rename($f . '.tmp', $f);
    @chmod($f, 0600);
}
function pkg_log($msg) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
    @file_put_contents(PKG_DIR . '/data/gate.log', date('c') . " $ip $msg\n", FILE_APPEND | LOCK_EX);
}
function pkg_valid_cookie() {
    $v = $_COOKIE[PKG_COOKIE] ?? '';
    if (!preg_match('/^(\d+)\.([A-Za-z0-9_-]{8,64})\.([a-f0-9]{64})$/', $v, $m)) { return false; }
    if ((int)$m[1] < time()) { return false; }
    $creds = pkg_creds();
    if (!isset($creds[$m[2]])) { return false; } // Passkey entfernt -> Cookie ungueltig
    $sig = hash_hmac('sha256', $m[1] . '.' . $m[2], pkg_secret());
    return hash_equals($sig, $m[3]);
}
function pkg_set_cookie($credKey) {
    $exp = time() + PKG_DAYS * 86400;
    $sig = hash_hmac('sha256', $exp . '.' . $credKey, pkg_secret());
    setcookie(PKG_COOKIE, $exp . '.' . $credKey . '.' . $sig, [
        'expires' => $exp, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
    ]);
}
function pkg_key($binaryId) {
    return substr(rtrim(strtr(base64_encode(hash('sha256', $binaryId, true)), '+/', '-_'), '='), 0, 32);
}
function pkg_webauthn() {
    spl_autoload_register(function ($c) {
        if (strpos($c, 'lbuchs\\WebAuthn\\') === 0) {
            $f = PKG_DIR . '/lib/src/' . str_replace('\\', '/', substr($c, 16)) . '.php';
            if (is_file($f)) { require $f; }
        }
    });
    return new lbuchs\WebAuthn\WebAuthn(PKG_RP_NAME, PKG_RP_ID, ['none', 'packed', 'apple', 'android-key', 'android-safetynet', 'fido-u2f', 'tpm'], true);
}
function pkg_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}
function pkg_rate_limited() {
    // max. 20 Versuche pro 10 Minuten und IP
    $ip = preg_replace('/[^0-9a-f.:]/i', '', $_SERVER['REMOTE_ADDR'] ?? 'x');
    $f = PKG_DIR . '/data/rl_' . md5($ip);
    $now = time();
    $hits = is_file($f) ? array_filter(explode(',', file_get_contents($f)), function ($t) use ($now) { return $t > $now - 600; }) : [];
    $hits[] = $now;
    file_put_contents($f, implode(',', $hits), LOCK_EX);
    return count($hits) > 20;
}

$pkgPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

/* ---------- Gate-Endpunkte ---------- */
if (strpos($pkgPath, '/__pk/') === 0) {
    header('X-Robots-Tag: noindex, nofollow');
    session_name('PKGATE');
    session_set_cookie_params(['path' => '/__pk/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    $action = substr($pkgPath, 6);
    $creds = pkg_creds();
    $loggedIn = pkg_valid_cookie();
    $mayRegister = empty($creds) || $loggedIn;

    if (in_array($action, ['reg-options', 'reg-verify', 'login-options', 'login-verify'], true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { pkg_json(['error' => 'POST erforderlich'], 405); }
        if (pkg_rate_limited()) { pkg_log('rate-limit'); pkg_json(['error' => 'Zu viele Versuche, bitte 10 Minuten warten.'], 429); }
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        try {
            $wa = pkg_webauthn();
            switch ($action) {
                case 'reg-options':
                    if (!$mayRegister) { pkg_json(['error' => 'Nicht erlaubt'], 403); }
                    $exclude = [];
                    foreach ($creds as $c) { $exclude[] = base64_decode($c['id']); }
                    $args = $wa->getCreateArgs(random_bytes(16), 'mail-zentrale', 'Mail-Zentrale', 120, true, 'required', null, $exclude);
                    $_SESSION['pk_challenge'] = $wa->getChallenge()->getBinaryString();
                    $_SESSION['pk_mode'] = 'reg';
                    pkg_json($args);
                case 'reg-verify':
                    if (!$mayRegister || ($_SESSION['pk_mode'] ?? '') !== 'reg') { pkg_json(['error' => 'Nicht erlaubt'], 403); }
                    $ch = $_SESSION['pk_challenge'] ?? ''; unset($_SESSION['pk_challenge'], $_SESSION['pk_mode']);
                    $data = $wa->processCreate(
                        base64_decode($in['clientDataJSON'] ?? ''),
                        base64_decode($in['attestationObject'] ?? ''),
                        new lbuchs\WebAuthn\Binary\ByteBuffer($ch), true, true, false
                    );
                    $key = pkg_key($data->credentialId);
                    $name = trim(preg_replace('/[^\p{L}\p{N} ._-]/u', '', (string)($in['name'] ?? ''))) ?: ('Passkey ' . date('d.m.Y H:i'));
                    $creds[$key] = [
                        'id' => base64_encode($data->credentialId),
                        'pub' => $data->credentialPublicKey,
                        'count' => (int)($data->signatureCounter ?? 0),
                        'name' => mb_substr($name, 0, 60),
                        'created' => date('c'),
                    ];
                    pkg_save_creds($creds);
                    pkg_log("registriert $key " . $creds[$key]['name']);
                    pkg_set_cookie($key);
                    pkg_json(['ok' => true]);
                case 'login-options':
                    if (empty($creds)) { pkg_json(['error' => 'Noch kein Passkey eingerichtet'], 400); }
                    $ids = [];
                    foreach ($creds as $c) { $ids[] = base64_decode($c['id']); }
                    $args = $wa->getGetArgs($ids, 120, true, true, true, true, true, 'required');
                    $_SESSION['pk_challenge'] = $wa->getChallenge()->getBinaryString();
                    $_SESSION['pk_mode'] = 'login';
                    pkg_json($args);
                case 'login-verify':
                    if (($_SESSION['pk_mode'] ?? '') !== 'login') { pkg_json(['error' => 'Sitzung abgelaufen, bitte neu laden'], 400); }
                    $ch = $_SESSION['pk_challenge'] ?? ''; unset($_SESSION['pk_challenge'], $_SESSION['pk_mode']);
                    $rawId = base64_decode($in['id'] ?? '');
                    $key = pkg_key($rawId);
                    if (!isset($creds[$key])) { pkg_log('unbekannter Passkey'); pkg_json(['error' => 'Unbekannter Passkey'], 403); }
                    $wa->processGet(
                        base64_decode($in['clientDataJSON'] ?? ''),
                        base64_decode($in['authenticatorData'] ?? ''),
                        base64_decode($in['signature'] ?? ''),
                        $creds[$key]['pub'],
                        new lbuchs\WebAuthn\Binary\ByteBuffer($ch),
                        null, true, true
                    );
                    $creds[$key]['last'] = date('c');
                    pkg_save_creds($creds);
                    pkg_log("login $key");
                    pkg_set_cookie($key);
                    pkg_json(['ok' => true]);
            }
        } catch (Throwable $e) {
            pkg_log('fehler ' . $action . ': ' . $e->getMessage());
            pkg_json(['error' => 'Prüfung fehlgeschlagen: ' . $e->getMessage()], 400);
        }
    }

    if ($action === 'logout') {
        setcookie(PKG_COOKIE, '', ['expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        header('Location: /__pk/login');
        exit;
    }

    // Seiten: login (Standard) und add (weiteren Passkey hinzufuegen)
    $mode = empty($creds) ? 'setup' : (($action === 'add' && $loggedIn) ? 'add' : 'login');
    if ($mode === 'login' && $loggedIn && $action !== 'add') { header('Location: /'); exit; }
    $back = isset($_GET['r']) && preg_match('#^/[^/\\\\]#', $_GET['r']) ? $_GET['r'] : '/';
    require PKG_DIR . '/page.php';
    exit;
}

/* ---------- Alle anderen Aufrufe: Cookie pruefen ---------- */
if (!pkg_valid_cookie()) {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET';
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Passkey-Anmeldung erforderlich';
        exit;
    }
    header('Location: /__pk/login?r=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'), true, 302);
    exit;
}
