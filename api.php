<?php
/* Ridge Games server: players, logins, events (one game played), results with coins, photos.
   Storage: one SQLite file + a photos folder, kept OUTSIDE the web folder when possible.
   Every call: api.php?a=<action>. Writes are POST with JSON and need the X-RG header. */

declare(strict_types=1);
ini_set('display_errors', '0'); // a PHP warning must never break the JSON
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const PHOTO_MAX = 1048576; // 1 MB
const DEFAULT_COINS = [10, 7, 5, 4, 3, 2, 1];
const ROSTER = [
  ['raffael', 'Raffael', 'RF', '#d4f09a', 1],
  ['rasha', 'Rasha', 'RS', '#f7b8d2', 0],
  ['rami', 'Rami', 'RM', '#aab7ff', 0],
  ['yara', 'Yara', 'YR', '#ffe08a', 0],
  ['yazan', 'Yazan', 'YZ', '#ffbf99', 0],
  ['rose', 'Rose', 'Ro', '#ff9fb8', 0],
  ['sako', 'Sako', 'SK', '#7ee8f0', 0],
  ['sofia', 'Sofia', 'SF', '#9df0c8', 0],
  ['jakob', 'Jakob', 'Ja', '#d8c39a', 0],
];

function data_dir(): string {
  $env = getenv('RG_DATA');
  if ($env) { if (!is_dir($env)) mkdir($env, 0700, true); return $env; }
  $outside = dirname(__DIR__, 2) . '/ridge-data';
  if (is_dir($outside) || @mkdir($outside, 0700, true)) {
    if (is_writable($outside)) return $outside;
  }
  $inside = __DIR__ . '/data';
  if (!is_dir($inside)) mkdir($inside, 0700, true);
  if (!file_exists("$inside/.htaccess")) file_put_contents("$inside/.htaccess", "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
  return $inside;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $dir = data_dir();
  if (!is_dir("$dir/photos")) mkdir("$dir/photos", 0700, true);
  $pdo = new PDO('sqlite:' . $dir . '/ridge.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  // DELETE journal (not WAL): WAL needs shared memory next to the file, which some shared hosts' file systems don't do well.
  $pdo->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=DELETE; PRAGMA busy_timeout=5000;');
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS players(id TEXT PRIMARY KEY, name TEXT NOT NULL, short TEXT NOT NULL, color TEXT NOT NULL,
      avatar TEXT, is_admin INTEGER NOT NULL DEFAULT 0, pass_hash TEXT, active INTEGER NOT NULL DEFAULT 1, sort INTEGER NOT NULL DEFAULT 0);
    CREATE TABLE IF NOT EXISTS events(id INTEGER PRIMARY KEY AUTOINCREMENT, game TEXT NOT NULL, category TEXT NOT NULL DEFAULT '',
      mode TEXT NOT NULL DEFAULT 'solo', played_at TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'open', photo TEXT,
      note TEXT NOT NULL DEFAULT '', created_by TEXT, score_dir TEXT NOT NULL DEFAULT 'high');
    CREATE TABLE IF NOT EXISTS results(event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
      player_id TEXT NOT NULL REFERENCES players(id), team TEXT, place INTEGER, coins INTEGER NOT NULL DEFAULT 0, score REAL,
      PRIMARY KEY(event_id, player_id));
    CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY, v TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS attempts(ip TEXT NOT NULL, at INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS rooms(id INTEGER PRIMARY KEY AUTOINCREMENT, game TEXT NOT NULL, host TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'lobby',
      state TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL);
  ");
  if (!(int)$pdo->query('SELECT COUNT(*) FROM players')->fetchColumn()) {
    $ins = $pdo->prepare('INSERT INTO players(id,name,short,color,is_admin,sort) VALUES(?,?,?,?,?,?)');
    foreach (ROSTER as $i => $p) $ins->execute([$p[0], $p[1], $p[2], $p[3], $p[4], $i]);
  }
  return $pdo;
}

define('RG_API', 1);
require __DIR__ . '/poker.php';
const ROOM_IDLE = 43200; // rooms quiet for 12 hours drop off the list
const SKIP_AFTER = 60;   // the host may skip a player whose turn has lasted this many seconds

function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit; }
function fail(string $msg, int $code = 400): void { out(['error' => $msg], $code); }

function body(): array {
  $raw = file_get_contents('php://input') ?: '';
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

function setting(string $k, $default) {
  $v = db()->prepare('SELECT v FROM settings WHERE k=?'); $v->execute([$k]);
  $r = $v->fetchColumn();
  return $r === false ? $default : json_decode($r, true);
}

/* raffaelco.com's front page can send visitors to the games. We only ever touch an index.html we wrote ourselves (it carries FRONT_MARK). */
const FRONT_MARK = '<!-- ridge-games-front-redirect -->';
function front_file(): ?string {
  if (getenv('RG_DATA') || basename(__DIR__) !== 'ridge-games') return null; // only when installed as public_html/ridge-games
  return dirname(__DIR__) . '/index.html';
}
function front_on(): bool { $f = front_file(); return $f && is_file($f) && str_contains((string)file_get_contents($f), FRONT_MARK); }
function front_free(): bool { $root = dirname(__DIR__); foreach (['index.html', 'index.php', 'index.htm', 'default.php'] as $n) if (file_exists("$root/$n")) return false; return true; }
function front_set(bool $on): void {
  $f = front_file(); if (!$f) fail('This only works on the real website.');
  if ($on) {
    if (front_on()) return;
    if (!front_free()) fail('The main site already has its own front page, so I left it alone.');
    $html = "<!doctype html>\n" . FRONT_MARK . "\n<meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n<title>Ridge Games</title>\n<meta http-equiv=\"refresh\" content=\"0; url=/ridge-games/\">\n<script>location.replace('/ridge-games/')</script>\n<p style=\"font-family:system-ui;text-align:center;margin-top:20vh\"><a href=\"/ridge-games/\">Open Ridge Games</a></p>\n";
    if (file_put_contents($f, $html) === false) fail('Could not write the front page.');
  } elseif (front_on()) {
    @unlink($f);
  }
}
function front_auto(): void { // first run: switch the redirect on once, as Sako asked; after that only the admin toggle changes it
  if (!front_file() || setting('front_auto', 0)) return;
  if (front_free()) { try { front_set(true); } catch (Throwable $e) { return; } }
  db()->prepare('INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['front_auto', '1']);
}

function coins_table(): array { return setting('coins', DEFAULT_COINS); }
function coins_for(int $place): int { $t = coins_table(); return $place >= 1 && $place <= count($t) ? (int)$t[$place - 1] : 0; }

function me(): ?array {
  if (empty($_SESSION['pid'])) return null;
  $s = db()->prepare('SELECT id,name,short,color,avatar,is_admin FROM players WHERE id=? AND active=1'); $s->execute([$_SESSION['pid']]);
  $r = $s->fetch();
  return $r ?: null;
}
function need_login(): array { $m = me(); if (!$m) fail('Please log in.', 401); return $m; }
function need_admin(): array { $m = need_login(); if (!(int)$m['is_admin']) fail('Only the admin can do that.', 403); return $m; }

function clean_color(string $c): string { return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : '#9aa3b3'; }
function clean_text($s, int $max): string { return mb_substr(trim((string)$s), 0, $max); }
function clean_avatar($a): ?string {
  if ($a === null || $a === '') return null;
  $a = (string)$a;
  if (strlen($a) > 120000 || !preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#', $a)) fail('That picture is too big or not an image.');
  return $a;
}

function players_public(bool $all = false): array {
  $q = 'SELECT id,name,short,color,avatar,is_admin,active,(pass_hash IS NOT NULL) AS has_pass FROM players' . ($all ? '' : ' WHERE active=1') . ' ORDER BY sort,name';
  $rows = db()->query($q)->fetchAll();
  foreach ($rows as &$r) { $r['is_admin'] = (int)$r['is_admin']; $r['active'] = (int)$r['active']; $r['has_pass'] = (int)$r['has_pass']; }
  return $rows;
}

function event_full(int $id): ?array {
  $s = db()->prepare('SELECT * FROM events WHERE id=?'); $s->execute([$id]);
  $e = $s->fetch(); if (!$e) return null;
  $r = db()->prepare('SELECT player_id,team,place,coins,score FROM results WHERE event_id=? ORDER BY place IS NULL, place, player_id'); $r->execute([$id]);
  $e['results'] = $r->fetchAll();
  foreach ($e['results'] as &$x) { $x['place'] = $x['place'] === null ? null : (int)$x['place']; $x['coins'] = (int)$x['coins']; $x['score'] = $x['score'] === null ? null : (float)$x['score']; }
  $e['id'] = (int)$e['id']; $e['has_photo'] = $e['photo'] ? 1 : 0; unset($e['photo']);
  return $e;
}

/* Places from the admin's list. Solo: one row per player. Teams: the team gets the place and every member gets its coins. */
function save_results(int $eid, string $mode, array $rows): void {
  $ok = array_column(players_public(true), 'id');
  db()->prepare('DELETE FROM results WHERE event_id=?')->execute([$eid]);
  $ins = db()->prepare('INSERT INTO results(event_id,player_id,team,place,coins,score) VALUES(?,?,?,?,?,?)');
  $seen = [];
  foreach ($rows as $r) {
    $pid = (string)($r['player_id'] ?? '');
    if (!in_array($pid, $ok, true) || isset($seen[$pid])) continue;
    $seen[$pid] = 1;
    $place = isset($r['place']) && $r['place'] !== '' && $r['place'] !== null ? max(1, (int)$r['place']) : null;
    $score = isset($r['score']) && $r['score'] !== '' && $r['score'] !== null && is_numeric($r['score']) ? (float)$r['score'] : null;
    $team = $mode === 'teams' ? clean_text($r['team'] ?? '', 40) : null;
    $ins->execute([$eid, $pid, $team ?: null, $place, $place ? coins_for($place) : 0, $score]);
  }
}

/* Wrong-password brake. Everyone on the trip shares one Wi-Fi address, so count per address AND profile
   (10 misses lock that profile for 15 min) plus a looser cap per address (40), so one person can't lock out the rest. */
function rate_limited(string $pid): bool {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
  db()->prepare('DELETE FROM attempts WHERE at<?')->execute([time() - 900]);
  $s = db()->prepare('SELECT COUNT(*) FROM attempts WHERE ip=?'); $s->execute(["$ip|$pid"]);
  if ((int)$s->fetchColumn() >= 10) return true;
  $s = db()->prepare("SELECT COUNT(*) FROM attempts WHERE ip LIKE ? ESCAPE '\\'"); $s->execute([addcslashes($ip, '%_\\') . '|%']);
  return (int)$s->fetchColumn() >= 40;
}
function note_attempt(string $pid): void { db()->prepare('INSERT INTO attempts(ip,at) VALUES(?,?)')->execute([($_SERVER['REMOTE_ADDR'] ?? '?') . '|' . $pid, time()]); }
function clean_when($w): string { // local "YYYY-MM-DDTHH:MM", nothing else
  $w = (string)$w;
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):([0-5]\d)/', $w, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return substr($w, 0, 16);
  return date('Y-m-d\TH:i');
}

/* ---------- online poker rooms ----------
   One row per table; state is JSON (poker.php). Every change runs inside BEGIN IMMEDIATE (one writer at a time)
   and bumps version (what polling compares). Game moves also bump state.seq; act/skip/next send the seq they saw,
   so a stale or double tap can never act twice, while someone sitting down meanwhile does not get in the way. */
function room_load(int $id): array {
  $s = db()->prepare('SELECT * FROM rooms WHERE id=?'); $s->execute([$id]);
  $r = $s->fetch(); if (!$r) fail('That table was not found.', 404);
  $r['state'] = json_decode($r['state'], true); $r['version'] = (int)$r['version'];
  return $r;
}
/* Load, change, save. $fn gets the room by reference and may throw RuntimeException (shown to the player). */
function room_change(int $id, callable $fn, ?int $expect = null): array {
  $pdo = db(); $pdo->exec('BEGIN IMMEDIATE');
  try {
    $r = room_load($id);
    if ($expect !== null && $expect !== (int)($r['state']['seq'] ?? 0)) throw new RuntimeException('The table moved on. Look again.');
    $fn($r);
    $pdo->prepare('UPDATE rooms SET status=?, state=?, version=version+1, updated_at=? WHERE id=? AND version=?')
      ->execute([$r['status'], json_encode($r['state'], JSON_UNESCAPED_SLASHES), time(), $id, $r['version']]);
    $pdo->exec('COMMIT');
  } catch (Throwable $e) {
    try { $pdo->exec('ROLLBACK'); } catch (Throwable $x) {}
    if ($e instanceof RuntimeException) fail($e->getMessage(), $expect !== null && str_starts_with($e->getMessage(), 'The table moved') ? 409 : 400);
    throw $e;
  }
  $r['version']++;
  return $r;
}
function room_need_host(array $r, array $m): void { if ($r['host'] !== $m['id']) throw new RuntimeException('Only the host can do that.'); }
function room_out(array $r, array $m): void { out(['room' => room_view($r, $m['id'], time())]); }

/* ---------- start ---------- */
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443)
  || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on');
$cookiePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/'; // only /ridge-games/, not the whole domain
$a = (string)($_GET['a'] ?? '');
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($post && ($_SERVER['HTTP_X_RG'] ?? '') !== '1') fail('Bad request.', 400);

try {
session_name('rgsess');
session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 60, 'path' => $cookiePath, 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
ini_set('session.use_strict_mode', '1'); // never adopt a session id the browser made up
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 60));
ini_set('session.gc_probability', '1'); ini_set('session.gc_divisor', '100'); // our own folder: nobody else cleans it
session_save_path(data_dir());
if (!session_start()) fail('Server problem. Try again.', 500);
// Only these change the session; release the lock for the rest so photos and lists load in parallel.
if (!in_array($a, ['setup', 'login', 'logout', 'me_update'], true)) session_write_close();

switch ($a) {

case 'state': // who am I, who can log in, is the admin password set yet
  front_auto();
  $adminSet = (int)db()->query('SELECT COUNT(*) FROM players WHERE is_admin=1 AND pass_hash IS NOT NULL')->fetchColumn() > 0;
  out(['me' => me(), 'players' => players_public(), 'admin_ready' => $adminSet, 'coins' => coins_table()]);

case 'players':
  front_auto();
  out(['players' => players_public()]);

case 'setup': // first run only: the admin sets his own password
  if (!$post) fail('Use POST.');
  $adminSet = (int)db()->query('SELECT COUNT(*) FROM players WHERE is_admin=1 AND pass_hash IS NOT NULL')->fetchColumn() > 0;
  if ($adminSet) fail('The admin password is already set. Pick your profile and log in.', 403);
  $b = body(); $pw = (string)($b['password'] ?? '');
  if (mb_strlen($pw) < 6) fail('Use at least 6 characters.');
  $adm = db()->query('SELECT id FROM players WHERE is_admin=1 ORDER BY sort LIMIT 1')->fetchColumn();
  db()->prepare('UPDATE players SET pass_hash=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $adm]);
  session_regenerate_id(true); $_SESSION['pid'] = $adm;
  out(['ok' => 1, 'me' => me()]);

case 'login':
  if (!$post) fail('Use POST.');
  $b = body(); $lid = substr((string)($b['id'] ?? ''), 0, 40);
  if (rate_limited($lid)) fail('Too many wrong tries. Wait 15 minutes.', 429);
  $s = db()->prepare('SELECT id,pass_hash FROM players WHERE id=? AND active=1'); $s->execute([$lid]);
  $p = $s->fetch();
  if (!$p || !$p['pass_hash']) { note_attempt($lid); fail($p ? 'No password yet. Ask the admin to set one.' : 'Wrong name or password.', 401); }
  if (!password_verify((string)($b['password'] ?? ''), $p['pass_hash'])) { note_attempt($lid); fail('Wrong password.', 401); }
  session_regenerate_id(true); $_SESSION['pid'] = $p['id'];
  out(['ok' => 1, 'me' => me()]);

case 'logout':
  if (!$post) fail('Use POST.');
  $_SESSION = []; session_destroy();
  setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $cookiePath, 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
  out(['ok' => 1]);

case 'me_update': // a player edits their own profile or password
  if (!$post) fail('Use POST.');
  $m = need_login(); $b = body();
  if (isset($b['new_password'])) {
    $s = db()->prepare('SELECT pass_hash FROM players WHERE id=?'); $s->execute([$m['id']]);
    if (!password_verify((string)($b['password'] ?? ''), (string)$s->fetchColumn())) fail('Your current password is wrong.', 401);
    if (mb_strlen((string)$b['new_password']) < 4) fail('Use at least 4 characters.');
    db()->prepare('UPDATE players SET pass_hash=? WHERE id=?')->execute([password_hash((string)$b['new_password'], PASSWORD_DEFAULT), $m['id']]);
    session_regenerate_id(true);
  }
  if (isset($b['name'])) {
    $name = clean_text($b['name'], 30); $short = clean_text($b['short'] ?? '', 3);
    if ($name === '' || $short === '') fail('Name and short letters are needed.');
    db()->prepare('UPDATE players SET name=?,short=?,color=?,avatar=? WHERE id=?')
      ->execute([$name, $short, clean_color((string)($b['color'] ?? '')), clean_avatar($b['avatar'] ?? null), $m['id']]);
  }
  out(['ok' => 1, 'me' => me()]);

/* ---------- admin: players ---------- */
case 'admin_players':
  need_admin(); out(['players' => players_public(true)]);

case 'bootstrap_pw': // one-time: give every non-admin player WITHOUT a password the owner's chosen shared password; locks itself after one use
  if (setting('bootstrap_pw_done', 0)) fail('Already used.', 403);
  $pw = (string)($_GET['pw'] ?? '');
  if (mb_strlen($pw) < 4) fail('Use at least 4 characters.');
  $n = db()->prepare('UPDATE players SET pass_hash=? WHERE is_admin=0 AND pass_hash IS NULL');
  $n->execute([password_hash($pw, PASSWORD_DEFAULT)]);
  db()->prepare('INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['bootstrap_pw_done', '1']);
  out(['ok' => 1, 'changed' => $n->rowCount()]);

case 'password_all': // one shared password for every player who isn't an admin
  if (!$post) fail('Use POST.');
  need_admin(); $pw = (string)(body()['password'] ?? '');
  if (mb_strlen($pw) < 4) fail('Use at least 4 characters.');
  $n = db()->prepare('UPDATE players SET pass_hash=? WHERE is_admin=0');
  $n->execute([password_hash($pw, PASSWORD_DEFAULT)]);
  out(['ok' => 1, 'changed' => $n->rowCount(), 'players' => players_public(true)]);

case 'player_save':
  if (!$post) fail('Use POST.');
  $adm = need_admin(); $b = body();
  $name = clean_text($b['name'] ?? '', 30); $short = clean_text($b['short'] ?? '', 3);
  if ($name === '' || $short === '') fail('Name and short letters are needed.');
  $id = (string)($b['id'] ?? '');
  if ($id === '') {
    $base = preg_replace('/[^a-z0-9]/', '', strtolower($name)) ?: 'player'; $id = $base; $n = 2;
    $chk = db()->prepare('SELECT 1 FROM players WHERE id=?');
    while (true) { $chk->execute([$id]); if (!$chk->fetchColumn()) break; $id = $base . $n++; }
    $sort = (int)db()->query('SELECT COALESCE(MAX(sort),0)+1 FROM players')->fetchColumn();
    db()->prepare('INSERT INTO players(id,name,short,color,sort) VALUES(?,?,?,?,?)')->execute([$id, $name, $short, clean_color((string)($b['color'] ?? '')), $sort]);
  } else {
    $chk = db()->prepare('SELECT 1 FROM players WHERE id=?'); $chk->execute([$id]);
    if (!$chk->fetchColumn()) fail('That player was not found.', 404);
  }
  if (!empty($b['password']) && mb_strlen((string)$b['password']) < 4) fail('Use at least 4 characters.');
  $isAdmin = !empty($b['is_admin']) ? 1 : 0; $active = array_key_exists('active', $b) ? (!empty($b['active']) ? 1 : 0) : 1;
  if ($id === $adm['id']) { $isAdmin = 1; $active = 1; } // the admin cannot lock himself out
  db()->prepare('UPDATE players SET name=?,short=?,color=?,is_admin=?,active=? WHERE id=?')
    ->execute([$name, $short, clean_color((string)($b['color'] ?? '')), $isAdmin, $active, $id]);
  if (array_key_exists('avatar', $b)) db()->prepare('UPDATE players SET avatar=? WHERE id=?')->execute([clean_avatar($b['avatar']), $id]);
  if (!empty($b['password'])) {
    db()->prepare('UPDATE players SET pass_hash=? WHERE id=?')->execute([password_hash((string)$b['password'], PASSWORD_DEFAULT), $id]);
  }
  out(['ok' => 1, 'id' => $id, 'players' => players_public(true)]);

/* ---------- events ---------- */
case 'events': // everything the dashboard needs
  need_login();
  $ids = db()->query("SELECT id FROM events ORDER BY played_at DESC, id DESC")->fetchAll(PDO::FETCH_COLUMN);
  $list = array_map(fn($i) => event_full((int)$i), $ids);
  out(['events' => $list, 'players' => players_public(true), 'coins' => coins_table()]);

case 'event_save': // create or edit; status 'done' records it on the dashboard
  if (!$post) fail('Use POST.');
  $adm = need_admin(); $b = body();
  $game = clean_text($b['game'] ?? '', 60); if ($game === '') fail('Pick or type the game.');
  $mode = ($b['mode'] ?? 'solo') === 'teams' ? 'teams' : 'solo';
  $status = ($b['status'] ?? 'open') === 'done' ? 'done' : 'open';
  $when = clean_when($b['played_at'] ?? '');
  $dir = ($b['score_dir'] ?? 'high') === 'low' ? 'low' : 'high';
  $id = (int)($b['id'] ?? 0);
  db()->beginTransaction(); // the game and its places are saved together or not at all
  if ($id) {
    if (!event_full($id)) { db()->rollBack(); fail('That game was not found. Maybe it was deleted.', 404); }
    db()->prepare('UPDATE events SET game=?,category=?,mode=?,played_at=?,status=?,note=?,score_dir=? WHERE id=?')
      ->execute([$game, clean_text($b['category'] ?? '', 30), $mode, $when, $status, clean_text($b['note'] ?? '', 500), $dir, $id]);
  } else {
    db()->prepare('INSERT INTO events(game,category,mode,played_at,status,note,created_by,score_dir) VALUES(?,?,?,?,?,?,?,?)')
      ->execute([$game, clean_text($b['category'] ?? '', 30), $mode, $when, $status, clean_text($b['note'] ?? '', 500), $adm['id'], $dir]);
    $id = (int)db()->lastInsertId();
  }
  save_results($id, $mode, is_array($b['results'] ?? null) ? $b['results'] : []);
  db()->commit();
  out(['ok' => 1, 'event' => event_full($id)]);

case 'event_delete':
  if (!$post) fail('Use POST.');
  need_admin(); $id = (int)(body()['id'] ?? 0);
  $s = db()->prepare('SELECT photo FROM events WHERE id=?'); $s->execute([$id]); $ph = $s->fetchColumn();
  if ($ph) @unlink(data_dir() . '/photos/' . basename($ph));
  db()->prepare('DELETE FROM events WHERE id=?')->execute([$id]);
  out(['ok' => 1]);

/* ---------- photos: the browser shrinks them; the server checks size and type again ---------- */
case 'photo_upload':
  if (!$post) fail('Use POST.');
  need_admin();
  $id = (int)($_GET['event'] ?? 0); if (!event_full($id)) fail('That game was not found.', 404);
  $raw = file_get_contents('php://input') ?: '';
  if (strlen($raw) === 0) fail('No photo received.');
  if (strlen($raw) > PHOTO_MAX) fail('The photo is over 1 MB.');
  $info = @getimagesizefromstring($raw);
  if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_PNG], true)) fail('That file is not a photo.');
  $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_PNG => 'png'][$info[2]];
  $s = db()->prepare('SELECT photo FROM events WHERE id=?'); $s->execute([$id]); $old = $s->fetchColumn();
  $name = $id . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
  if (file_put_contents(data_dir() . '/photos/' . $name, $raw) !== strlen($raw)) { @unlink(data_dir() . '/photos/' . $name); fail('Could not store the photo. The server may be full.', 500); }
  db()->prepare('UPDATE events SET photo=? WHERE id=?')->execute([$name, $id]);
  if ($old) @unlink(data_dir() . '/photos/' . basename($old)); // the old one goes only once the new one is safe
  out(['ok' => 1, 'event' => event_full($id)]);

case 'photo_delete':
  if (!$post) fail('Use POST.');
  need_admin(); $id = (int)(body()['id'] ?? 0);
  $s = db()->prepare('SELECT photo FROM events WHERE id=?'); $s->execute([$id]); $ph = $s->fetchColumn();
  if ($ph) @unlink(data_dir() . '/photos/' . basename($ph));
  db()->prepare('UPDATE events SET photo=NULL WHERE id=?')->execute([$id]);
  out(['ok' => 1, 'event' => event_full($id)]);

case 'photo': // the picture itself, for logged-in players only
  need_login();
  $s = db()->prepare('SELECT photo FROM events WHERE id=?'); $s->execute([(int)($_GET['id'] ?? 0)]); $ph = $s->fetchColumn();
  $f = $ph ? data_dir() . '/photos/' . basename($ph) : '';
  if (!$f || !is_file($f)) fail('No photo.', 404);
  $types = ['jpg' => 'image/jpeg', 'webp' => 'image/webp', 'png' => 'image/png'];
  header('Content-Type: ' . ($types[pathinfo($f, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
  header('Cache-Control: private, max-age=86400');
  header('Content-Length: ' . filesize($f));
  readfile($f); exit;

/* ---------- settings + backup ---------- */
case 'settings_save':
  if (!$post) fail('Use POST.');
  need_admin(); $b = body();
  if (isset($b['coins']) && is_array($b['coins'])) {
    $c = array_values(array_map(fn($x) => max(0, min(1000, (int)$x)), array_slice($b['coins'], 0, 30)));
    if (!$c) fail('Add at least one place.');
    db()->beginTransaction();
    db()->prepare('INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['coins', json_encode($c)]);
    // Coins follow the table: re-count every recorded result.
    $rows = db()->query('SELECT event_id,player_id,place FROM results WHERE place IS NOT NULL')->fetchAll();
    $up = db()->prepare('UPDATE results SET coins=? WHERE event_id=? AND player_id=?');
    foreach ($rows as $r) $up->execute([coins_for((int)$r['place']), $r['event_id'], $r['player_id']]);
    db()->commit();
  }
  if (array_key_exists('front', $b)) front_set(!empty($b['front']));
  out(['ok' => 1, 'coins' => coins_table(), 'front' => front_on()]);

case 'front':
  need_admin(); out(['front' => front_on(), 'possible' => front_file() !== null]);

case 'export':
  need_admin();
  header('Content-Disposition: attachment; filename="ridge-games-backup-' . date('Y-m-d') . '.json"');
  $ids = db()->query('SELECT id FROM events ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
  out(['exported_at' => date('c'), 'players' => players_public(true), 'coins' => coins_table(), 'events' => array_map(fn($i) => event_full((int)$i), $ids)]);

/* ---------- online poker ---------- */
case 'room_list':
  need_login();
  $rows = db()->prepare("SELECT id,host,status,state,updated_at FROM rooms WHERE status IN ('lobby','playing') AND updated_at>? ORDER BY id DESC LIMIT 30");
  $rows->execute([time() - ROOM_IDLE]);
  out(['rooms' => array_map(function ($r) {
    $st = json_decode($r['state'], true);
    return ['id' => (int)$r['id'], 'host' => $r['host'], 'status' => $r['status'], 'chips' => $st['chips'], 'hand_no' => $st['hand_no'], 'seats' => array_column($st['seats'], 'id')];
  }, $rows->fetchAll())]);

case 'room_create':
  if (!$post) fail('Use POST.');
  $m = need_login(); $chips = (int)(body()['chips'] ?? 0);
  if (!in_array($chips, [100, 200, 500], true)) fail('Pick 100, 200 or 500 chips.');
  $st = room_new_state($chips); room_sit($st, $m, false);
  $t = time();
  db()->prepare('INSERT INTO rooms(game,host,status,state,created_at,updated_at) VALUES(?,?,?,?,?,?)')->execute(['poker', $m['id'], 'lobby', json_encode($st), $t, $t]);
  room_out(room_load((int)db()->lastInsertId()), $m);

case 'room_state': // cheap when nothing changed: {same:1}
  $m = need_login(); $id = (int)($_GET['id'] ?? 0);
  $s = db()->prepare('SELECT version FROM rooms WHERE id=?'); $s->execute([$id]); $ver = $s->fetchColumn();
  if ($ver === false) fail('That table was not found.', 404);
  if (isset($_GET['v']) && (int)$_GET['v'] === (int)$ver) out(['same' => 1, 'v' => (int)$ver]);
  room_out(room_load($id), $m);

case 'room_join':
case 'room_leave':
  if (!$post) fail('Use POST.');
  $m = need_login(); $id = (int)(body()['id'] ?? 0);
  room_out(room_change($id, function (&$r) use ($m, $a) {
    if (!in_array($r['status'], ['lobby', 'playing'], true)) throw new RuntimeException('This game is over.');
    $playing = $r['status'] === 'playing';
    if ($a === 'room_join') room_sit($r['state'], $m, $playing); else room_stand($r['state'], $m['id'], $playing);
  }), $m);

case 'room_start':
  if (!$post) fail('Use POST.');
  $m = need_login(); $id = (int)(body()['id'] ?? 0);
  room_out(room_change($id, function (&$r) use ($m) {
    room_need_host($r, $m);
    if ($r['status'] !== 'lobby') throw new RuntimeException('This game has already started.');
    if (count($r['state']['seats']) < 2) throw new RuntimeException('At least 2 players need a seat.');
    foreach ($r['state']['seats'] as $s) $r['state']['stacks'][$s['id']] = $r['state']['chips'];
    room_deal($r['state']); $r['status'] = 'playing';
  }), $m);

case 'room_act':
  if (!$post) fail('Use POST.');
  $m = need_login(); $b = body(); $id = (int)($b['id'] ?? 0);
  $act = (string)($b['act'] ?? '');
  if (!in_array($act, ['fold', 'check', 'call', 'raise', 'allin'], true)) fail('Unknown action.');
  $amt = null;
  if ($act === 'raise') {
    $raw = $b['amount'] ?? null;
    if (is_int($raw)) $amt = $raw; elseif (is_string($raw) && preg_match('/^\d{1,9}$/', $raw)) $amt = (int)$raw; else fail('The raise must be a whole number of chips.');
  }
  room_out(room_change($id, function (&$r) use ($m, $act, $amt) {
    $h = &$r['state']['hand'];
    if ($r['status'] !== 'playing' || !$h || $h['done']) throw new RuntimeException('No hand is being played.');
    if ($h['players'][$h['cur']]['id'] !== $m['id']) throw new RuntimeException("It's not your turn.");
    if ($amt !== null && $amt > $h['players'][$h['cur']]['bet'] + $h['players'][$h['cur']]['stack']) throw new RuntimeException("You don't have that many chips.");
    pk_act($h, $act, $amt); unset($h); room_after($r['state']);
  }, isset($b['seq']) ? (int)$b['seq'] : null), $m);

case 'room_skip': // host: the player to act has been away too long. Check if free, otherwise fold.
  if (!$post) fail('Use POST.');
  $m = need_login(); $b = body(); $id = (int)($b['id'] ?? 0);
  room_out(room_change($id, function (&$r) use ($m) {
    room_need_host($r, $m);
    $h = &$r['state']['hand'];
    if ($r['status'] !== 'playing' || !$h || $h['done']) throw new RuntimeException('No hand is being played.');
    if (time() - (int)$h['turn_at'] < SKIP_AFTER) throw new RuntimeException('Give them a minute first.');
    $o = pk_options($h);
    pk_act($h, $o['canCheck'] ? 'check' : 'fold'); $h['log'][count($h['log']) - 1] .= ' (skipped by the host)';
    unset($h); room_after($r['state']);
  }, isset($b['seq']) ? (int)$b['seq'] : null), $m);

case 'room_next':
  if (!$post) fail('Use POST.');
  $m = need_login(); $b = body(); $id = (int)($b['id'] ?? 0);
  room_out(room_change($id, function (&$r) use ($m) {
    room_need_host($r, $m);
    if ($r['status'] !== 'playing' || !$r['state']['hand']['done']) throw new RuntimeException('Finish this hand first.');
    room_deal($r['state']);
  }, isset($b['seq']) ? (int)$b['seq'] : null), $m);

case 'room_close': // host ends the game (a hand still running is called off) and the final ranking is shown
  if (!$post) fail('Use POST.');
  $m = need_login(); $id = (int)(body()['id'] ?? 0);
  room_out(room_change($id, function (&$r) use ($m) {
    room_need_host($r, $m);
    if ($r['status'] === 'done') throw new RuntimeException('This game is already over.');
    room_finish($r['state']); $r['status'] = 'done';
  }), $m);

default:
  fail('Unknown action.', 404);
}
} catch (Throwable $e) {
  try { if (db()->inTransaction()) db()->rollBack(); } catch (Throwable $x) {}
  error_log('ridge api: ' . $e->getMessage());
  fail('Server problem. Try again.', 500);
}
