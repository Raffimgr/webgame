<?php
/* Ridge Games: phone notifications that arrive even when the app is closed (Web Push; Sako asked, 2026-10-22).
   Each phone turns them on from the bell (rg.js); sw.js shows them. They cover what the bell lists: chat messages,
   new Feed posts, being tagged, likes and comments on your posts, game invites. Admin > Settings turns each kind on or off
   for everyone (settings key push = {off:[kinds]}), and Feed / Chat ones follow the Admin access switches.
   Kept in ridge-data, outside the web folder: push.sqlite (each phone's subscription) and push.json (the server's signing key,
   made on first use; it must never go in the repo). Sending happens after the reply has gone out, so nobody waits for it.
   No libraries: the message is encrypted as RFC 8291 says (aes128gcm) and signed for the push service as RFC 8292 says (VAPID).
   Included by api.php, chat.php, social.php and talk.php. */

declare(strict_types=1);
if (get_included_files()[0] === __FILE__) { http_response_code(404); exit; }

const PUSH_KINDS = ['chat', 'post', 'tag', 'like', 'comment', 'invite'];
const PUSH_FEED = ['post', 'tag', 'like', 'comment'];

function push_db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $f = data_dir() . '/push.sqlite';
  $pdo = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  $pdo->exec('PRAGMA busy_timeout=3000');
  $pdo->exec('CREATE TABLE IF NOT EXISTS subs (endpoint TEXT PRIMARY KEY, pid TEXT NOT NULL, p256dh TEXT NOT NULL, auth TEXT NOT NULL,
    device TEXT NOT NULL DEFAULT "", at INTEGER NOT NULL, ok_at INTEGER NOT NULL DEFAULT 0)');
  @chmod($f, 0600);
  return $pdo;
}
function push_ridge(): PDO {
  static $pdo = null;
  if (!$pdo) { $pdo = new PDO('sqlite:' . data_dir() . '/ridge.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); $pdo->exec('PRAGMA busy_timeout=3000'); }
  return $pdo;
}
function push_setting(string $k): array {
  $s = push_ridge()->prepare('SELECT v FROM settings WHERE k=?'); $s->execute([$k]);
  $v = json_decode((string)$s->fetchColumn(), true);
  return is_array($v) ? $v : [];
}
/* Which kinds are off for everyone (Admin > Settings > Phone notifications). */
function push_conf(): array { return ['off' => array_values(array_intersect((array)(push_setting('push')['off'] ?? []), PUSH_KINDS))]; }

function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
function push_point(OpenSSLAsymmetricKey $k): string { // 0x04 || x || y, the raw public key
  $d = openssl_pkey_get_details($k)['ec'];
  return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
}
function push_pubkey(string $point): OpenSSLAsymmetricKey { // a raw P-256 public key as an OpenSSL key
  $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
  $k = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
  if (!$k) throw new RuntimeException('bad key');
  return $k;
}
function push_newkey(): OpenSSLAsymmetricKey {
  $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
  if (!$k) throw new RuntimeException('no key');
  return $k;
}

/* The server's own key pair (VAPID). Phones get the public half when they subscribe. */
function push_keys(): array {
  static $keys = null;
  if ($keys) return $keys;
  $f = data_dir() . '/push.json';
  $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
  if (!is_array($j) || empty($j['pem'])) {
    $k = push_newkey(); openssl_pkey_export($k, $pem);
    $j = ['pem' => $pem, 'pub' => b64u(push_point($k)), 'at' => time()];
    file_put_contents($f, json_encode($j), LOCK_EX); @chmod($f, 0600);
  }
  return $keys = ['priv' => openssl_pkey_get_private($j['pem']), 'pub' => $j['pub']];
}

/* VAPID: a short-lived ES256 token for the push service at $endpoint's address. */
function push_jwt(string $endpoint): string {
  $u = parse_url($endpoint);
  $data = b64u('{"typ":"JWT","alg":"ES256"}') . '.' . b64u(json_encode(['aud' => $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : ''), 'exp' => time() + 3600, 'sub' => 'https://raffaelco.com/DM/'], JSON_UNESCAPED_SLASHES));
  openssl_sign($data, $der, push_keys()['priv'], OPENSSL_ALGO_SHA256);
  // OpenSSL gives the signature as DER (SEQUENCE of two INTEGERs); JWT wants the two numbers side by side, 32 bytes each
  $o = 3; $rl = ord($der[$o]); $r = substr($der, $o + 1, $rl); $o += 1 + $rl + 1; $sl = ord($der[$o]); $s = substr($der, $o + 1, $sl);
  $fix = fn($n) => str_pad(ltrim($n, "\0"), 32, "\0", STR_PAD_LEFT);
  return $data . '.' . b64u($fix($r) . $fix($s));
}

/* RFC 8291: encrypt $plain for one phone (its p256dh public key and auth secret). Returns the request body. */
function push_encrypt(string $plain, string $uaPub, string $auth, ?OpenSSLAsymmetricKey $eph = null, ?string $salt = null): string {
  $eph = $eph ?? push_newkey(); $salt = $salt ?? random_bytes(16);
  $asPub = push_point($eph);
  $shared = openssl_pkey_derive(push_pubkey($uaPub), $eph, 32);
  if ($shared === false) throw new RuntimeException('derive failed');
  $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPub . $asPub, $auth);
  $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
  $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
  $ct = openssl_encrypt($plain . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
  return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
}

/* Queue a notification for these members; it goes out once the reply has been sent.
   $msg: title, body, url (relative to the site, like chat.html), tag (a new one with the same tag replaces the old). */
function push_later(array $pids, string $kind, array $msg): void {
  static $hooked = false;
  try {
    if (!in_array($kind, PUSH_KINDS, true) || in_array($kind, push_conf()['off'], true)) return;
    $pids = array_values(array_unique(array_filter($pids, 'is_string')));
    if (!$pids) return;
    $acc = push_setting('access'); $no = in_array($kind, PUSH_FEED, true) ? (array)($acc['nofeed'] ?? []) : ($kind === 'chat' ? (array)($acc['nochat'] ?? []) : []);
    $q = implode(',', array_fill(0, count($pids), '?'));
    $s = push_ridge()->prepare("SELECT * FROM players WHERE active=1 AND id IN ($q)"); $s->execute($pids);
    $to = [];
    foreach ($s->fetchAll() as $p) if (!(int)($p['screen'] ?? 0) && ((int)$p['is_admin'] || !in_array($p['id'], $no, true))) $to[] = $p['id'];
    if (!$to) return;
    $GLOBALS['RG_PUSH'][] = [$to, $msg + ['kind' => $kind]];
    if (!$hooked) { $hooked = true; register_shutdown_function('push_flush'); }
  } catch (Throwable $e) {}
}
function push_flush(): void {
  $jobs = $GLOBALS['RG_PUSH'] ?? []; $GLOBALS['RG_PUSH'] = [];
  if (!$jobs) return;
  ignore_user_abort(true); @set_time_limit(30);
  if (function_exists('litespeed_finish_request')) litespeed_finish_request(); elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
  foreach ($jobs as [$pids, $msg]) { try { push_send($pids, $msg); } catch (Throwable $e) {} }
}

/* Send now to every phone these members turned notifications on for. Returns how many went through. */
function push_send(array $pids, array $msg): int {
  if (!$pids || !function_exists('curl_multi_init')) return 0;
  $q = implode(',', array_fill(0, count($pids), '?'));
  $s = push_db()->prepare("SELECT * FROM subs WHERE pid IN ($q)"); $s->execute($pids);
  $subs = $s->fetchAll();
  if (!$subs) return 0;
  $body = json_encode(['title' => mb_substr((string)$msg['title'], 0, 80), 'body' => mb_substr((string)($msg['body'] ?? ''), 0, 180),
    'url' => (string)($msg['url'] ?? './'), 'tag' => (string)($msg['tag'] ?? $msg['kind'])], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  $mh = curl_multi_init(); $hs = [];
  foreach ($subs as $sub) {
    try { $data = push_encrypt($body, b64u_dec($sub['p256dh']), b64u_dec($sub['auth'])); } catch (Throwable $e) { continue; }
    $h = curl_init($sub['endpoint']);
    curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
      CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: 86400', 'Urgency: ' . ($msg['kind'] === 'invite' || $msg['kind'] === 'chat' ? 'high' : 'normal'),
        'Authorization: vapid t=' . push_jwt($sub['endpoint']) . ', k=' . push_keys()['pub']]]);
    curl_multi_add_handle($mh, $h); $hs[] = [$h, $sub['endpoint']];
  }
  do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);
  $ok = 0; $db = push_db();
  foreach ($hs as [$h, $ep]) {
    $code = (int)curl_getinfo($h, CURLINFO_HTTP_CODE);
    if ($code >= 200 && $code < 300) { $ok++; $db->prepare('UPDATE subs SET ok_at=? WHERE endpoint=?')->execute([time(), $ep]); }
    elseif ($code === 404 || $code === 410) $db->prepare('DELETE FROM subs WHERE endpoint=?')->execute([$ep]); // that phone turned them off or the subscription ran out
    curl_multi_remove_handle($mh, $h); curl_close($h);
  }
  curl_multi_close($mh);
  return $ok;
}

/* A phone turning notifications on (or a different member logging in on it): the endpoint is the phone's address at its push service. */
function push_subscribe(string $pid, array $sub, string $device): void {
  $ep = (string)($sub['endpoint'] ?? ''); $k = (array)($sub['keys'] ?? []);
  if (!preg_match('~^https://[a-z0-9.-]+(:\d+)?/~i', $ep) || strlen($ep) > 1000) throw new InvalidArgumentException('That notification address looks wrong.');
  $p = b64u_dec((string)($k['p256dh'] ?? '')); $a = b64u_dec((string)($k['auth'] ?? ''));
  if (strlen($p) !== 65 || $p[0] !== "\x04" || strlen($a) < 16) throw new InvalidArgumentException('That notification key looks wrong.');
  push_db()->prepare('INSERT INTO subs (endpoint, pid, p256dh, auth, device, at) VALUES (?, ?, ?, ?, ?, ?)
    ON CONFLICT(endpoint) DO UPDATE SET pid=excluded.pid, p256dh=excluded.p256dh, auth=excluded.auth, device=excluded.device')
    ->execute([$ep, $pid, b64u($p), b64u($a), $device, time()]);
}
function push_unsubscribe(string $pid, string $ep): void { push_db()->prepare('DELETE FROM subs WHERE endpoint=? AND pid=?')->execute([$ep, $pid]); }
/* For Admin > Settings: which members have notifications on, and on what. */
function push_phones(): array {
  try { return push_db()->query('SELECT pid, device, at, ok_at FROM subs ORDER BY pid, at')->fetchAll(); } catch (Throwable $e) { return []; }
}

/* ---- what each event says ---- */
function push_name(string $pid): string {
  try { $s = push_ridge()->prepare('SELECT name FROM players WHERE id=?'); $s->execute([$pid]); return (string)($s->fetchColumn() ?: 'Someone'); } catch (Throwable $e) { return 'Someone'; }
}
function push_everyone_but(string $pid): array {
  try { $s = push_ridge()->prepare('SELECT id FROM players WHERE active=1 AND id<>?'); $s->execute([$pid]); return $s->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { return []; }
}
function push_social(): ?PDO {
  $f = data_dir() . '/social.sqlite';
  return is_file($f) ? new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) : null;
}
/* chat.php: a new message in the group chat. Same tag, so a phone shows the newest one instead of a pile. */
function push_chat(string $by, string $body): void {
  push_later(push_everyone_but($by), 'chat', ['title' => '💬 ' . push_name($by), 'body' => $body, 'url' => 'chat.html', 'tag' => 'rg-chat']);
}
/* social.php: a post just went live. Tagged members hear "tagged you", everyone else "shared". */
function push_post(int $grp): void {
  try {
    $db = push_social(); if (!$db) return;
    $s = $db->prepare("SELECT pid, cap, tags, kind FROM posts WHERE grp=? AND state='live' ORDER BY id"); $s->execute([$grp]); $rows = $s->fetchAll();
    if (!$rows) return;
    $by = $rows[0]['pid']; $name = push_name($by); $cap = trim((string)$rows[0]['cap']);
    $n = count($rows); $v = count(array_filter($rows, fn($r) => $r['kind'] === 'video'));
    $what = $v === $n ? ($n > 1 ? "$n videos" : 'a video') : ($v ? "$n photos and videos" : ($n > 1 ? "$n photos" : 'a photo'));
    $tags = array_values(array_filter(explode(',', (string)$rows[0]['tags']), fn($t) => $t !== '' && $t !== $by));
    $url = 'social.html#po-' . $grp;
    push_later($tags, 'tag', ['title' => '📸 ' . $name . ' tagged you in a post', 'body' => $cap !== '' ? $cap : "Shared $what on the Feed", 'url' => $url, 'tag' => 'rg-po-' . $grp]);
    $tagOn = !in_array('tag', push_conf()['off'], true); // with "Tagged in a post" off, tagged members hear about it like everyone else
    push_later(array_diff(push_everyone_but($by), $tagOn ? $tags : []), 'post', ['title' => '📸 ' . $name . " shared $what", 'body' => $cap !== '' ? $cap : 'New on the Trip Feed', 'url' => $url, 'tag' => 'rg-po-' . $grp]);
  } catch (Throwable $e) {}
}
/* talk.php: a like or a comment on a Feed post (keys po-<post>) tells the person who posted it. */
function push_talk(string $item, string $by, string $kind, string $text = ''): void {
  try {
    if (!preg_match('/^po-(\d+)$/', $item, $m) || !($db = push_social())) return;
    $s = $db->prepare("SELECT pid, cap FROM posts WHERE grp=? AND state='live' ORDER BY id LIMIT 1"); $s->execute([(int)$m[1]]); $p = $s->fetch();
    if (!$p || $p['pid'] === $by) return;
    $cap = trim((string)$p['cap']); $name = push_name($by);
    if ($kind === 'like') push_later([$p['pid']], 'like', ['title' => '❤️ ' . $name . ' liked your post', 'body' => $cap, 'url' => 'social.html#' . $item, 'tag' => 'rg-like-' . $item]);
    else push_later([$p['pid']], 'comment', ['title' => '💬 ' . $name . ' commented on your post', 'body' => $text, 'url' => 'social.html#' . $item, 'tag' => 'rg-cm-' . $item]);
  } catch (Throwable $e) {}
}
