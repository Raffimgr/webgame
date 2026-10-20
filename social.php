<?php
/* The trip feed (social.html): photos and videos everyone shares. Photos (and video cover pictures) are kept in ridge-data/social,
   videos in Raffi's Cloudflare R2 bucket through the ridge-media Worker (cloudflare/media-worker.js). The list of posts is
   ridge-data/social.sqlite. Likes and comments go through talk.php (key po-<post id>). Nothing here is in this public repo.
   A post holds one or more photos or videos (swiped through like Instagram): each one is a row, and the rows of a post share
   its id in grp (the first row's id). The caption and tagged players are kept on every row of the post.

   Logged in:
     GET  social.php                    -> {posts:[{id, by, at, cap, tags, items:[{id, kind, key, name, size, w, h, dur, prev}]}], view, media}
     GET  social.php?f=ID[&s=1][&dl=1]  -> a photo (s=1: its smaller copy, or a video's cover picture); dl=1 downloads it
     POST {act:"start", kind:"photo"|"video", name, size, type, w, h, dur, cap, tags, grp?} -> {id, grp, key?, ticket?} (video: the Worker key and an upload ticket)
     POST social.php?up=main|prev&id=ID with a JPEG body -> the photo, or the smaller copy / video cover picture
     POST {act:"done", id, hold?}       -> the photo or video is ready; without hold it shows in the feed at once
     POST {act:"publish", grp}          -> every ready photo and video of the post shows in the feed together
     POST {act:"edit", id, cap, tags, drop?:[item ids]} -> changes the caption and tags, and can take photos out of the post
     POST {act:"drop", id}              -> removes one photo or video (an upload that failed)
     POST {act:"del", id}               -> removes a whole post
   Edit, drop and del are for whoever shared it, or the admin. They answer with vids:[{key, ticket}] for videos to delete from the Worker.
   Asked by the Worker, no login (answers only yes or no):
     GET  ?check=TICKET&key=KEY -> {ok:1, op:"put"|"del"}     GET ?view=TOKEN -> {ok:1, op:"view"} */

require __DIR__ . '/guide_lib.php';

const MEDIA_URL = 'https://ridge-media.raffaelco.workers.dev';
const PHOTO_MAX = 40 * 1048576;
const VIDEO_MAX = 4 * 1073741824;

function social_dir(): string { $d = data_dir() . '/social'; if (!is_dir($d)) mkdir($d, 0700, true); return $d; }
function social_db(): PDO {
  $f = data_dir() . '/social.sqlite';
  $pdo = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  $pdo->exec('PRAGMA busy_timeout=4000');
  $pdo->exec("CREATE TABLE IF NOT EXISTS posts (id INTEGER PRIMARY KEY AUTOINCREMENT, pid TEXT NOT NULL, kind TEXT NOT NULL, vkey TEXT,
    name TEXT NOT NULL, size INTEGER NOT NULL DEFAULT 0, w INTEGER, h INTEGER, dur REAL, cap TEXT NOT NULL DEFAULT '', prev INTEGER NOT NULL DEFAULT 0,
    state TEXT NOT NULL DEFAULT 'up', at INTEGER NOT NULL, grp INTEGER, tags TEXT NOT NULL DEFAULT '')");
  /* posts from before photos could be grouped: each one is a post of its own */
  $cols = array_column($pdo->query('PRAGMA table_info(posts)')->fetchAll(), 'name');
  if (!in_array('grp', $cols, true)) $pdo->exec('ALTER TABLE posts ADD COLUMN grp INTEGER');
  if (!in_array('tags', $cols, true)) $pdo->exec("ALTER TABLE posts ADD COLUMN tags TEXT NOT NULL DEFAULT ''");
  $pdo->exec('UPDATE posts SET grp=id WHERE grp IS NULL');
  $pdo->exec('CREATE TABLE IF NOT EXISTS tickets (t TEXT PRIMARY KEY, vkey TEXT NOT NULL, op TEXT NOT NULL, exp INTEGER NOT NULL)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS views (t TEXT PRIMARY KEY, pid TEXT NOT NULL, exp INTEGER NOT NULL)');
  @chmod($f, 0600);
  return $pdo;
}
function token(): string { return bin2hex(random_bytes(16)); }
function ticket(PDO $db, string $vkey, string $op): string {
  $t = token();
  $db->prepare('DELETE FROM tickets WHERE exp < ?')->execute([time()]);
  $db->prepare('INSERT INTO tickets (t, vkey, op, exp) VALUES (?, ?, ?, ?)')->execute([$t, $vkey, $op, time() + 12 * 3600]);
  return $t;
}
function item_row(array $r): array {
  return ['id' => (int)$r['id'], 'kind' => $r['kind'], 'key' => $r['vkey'], 'name' => $r['name'], 'size' => (int)$r['size'],
    'w' => $r['w'] === null ? null : (int)$r['w'], 'h' => $r['h'] === null ? null : (int)$r['h'], 'dur' => $r['dur'] === null ? null : (float)$r['dur'],
    'prev' => (int)$r['prev']];
}
/* live rows (oldest first within a post) -> posts, newest first */
function group_posts(array $rows): array {
  $posts = [];
  foreach ($rows as $r) {
    $g = (int)$r['grp'];
    if (!isset($posts[$g])) $posts[$g] = ['id' => $g, 'by' => $r['pid'], 'at' => (int)$r['at'], 'cap' => $r['cap'],
      'tags' => $r['tags'] === '' ? [] : explode(',', $r['tags']), 'items' => []];
    $posts[$g]['items'][] = item_row($r);
  }
  return array_values($posts);
}
function one_post(PDO $db, int $grp): ?array {
  $s = $db->prepare("SELECT * FROM posts WHERE grp=? AND state='live' ORDER BY id"); $s->execute([$grp]);
  return group_posts($s->fetchAll())[0] ?? null;
}
function can_change(string $owner, string $pid): void {
  if ($owner !== $pid && !guide_is_admin($pid)) fail('Only the person who shared it (or the admin) can do that.', 403);
}
function own_post(PDO $db, int $id, string $pid): array {
  $s = $db->prepare("SELECT * FROM posts WHERE id=? AND state != 'gone'"); $s->execute([$id]);
  $r = $s->fetch();
  if (!$r) fail('That post is gone.', 404);
  can_change($r['pid'], $pid);
  return $r;
}
/* all rows of a post that are still there */
function own_group(PDO $db, int $grp, string $pid): array {
  $s = $db->prepare("SELECT * FROM posts WHERE grp=? AND state != 'gone' ORDER BY id"); $s->execute([$grp]);
  $rows = $s->fetchAll();
  if (!$rows) fail('That post is gone.', 404);
  can_change($rows[0]['pid'], $pid);
  return $rows;
}
/* takes rows out: their files go, and videos get a ticket so the browser can delete them from the Worker */
function remove_rows(PDO $db, array $rows): array {
  $vids = [];
  foreach ($rows as $r) {
    $db->prepare("UPDATE posts SET state='gone' WHERE id=?")->execute([$r['id']]);
    @unlink(social_dir() . '/' . $r['id'] . '.jpg');
    @unlink(social_dir() . '/' . $r['id'] . '-s.jpg');
    if ($r['kind'] === 'video' && $r['vkey']) $vids[] = ['key' => $r['vkey'], 'ticket' => ticket($db, $r['vkey'], 'del')];
  }
  return $vids;
}
function clean_cap($v): string {
  return mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$v) ?? ''), 0, 500);
}
/* tagged players: active player ids only, each once */
function clean_tags($v): string {
  if (!is_array($v)) return '';
  $pdo = new PDO('sqlite:' . data_dir() . '/ridge.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $ids = $pdo->query('SELECT id FROM players WHERE active=1')->fetchAll(PDO::FETCH_COLUMN);
  return implode(',', array_slice(array_values(array_unique(array_filter(array_map('strval', $v), fn($t) => in_array($t, $ids, true)))), 0, 20));
}
function clean_name(string $s): string {
  $s = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($s)) ?? '';
  return substr(trim($s, '._') ?: 'file', -80);
}

try {
  /* the Worker asking about a ticket or a viewing token */
  if (isset($_GET['check']) || isset($_GET['view'])) {
    header('Cache-Control: no-store');
    $db = social_db();
    if (isset($_GET['view'])) {
      $s = $db->prepare('SELECT 1 FROM views WHERE t=? AND exp > ?'); $s->execute([(string)$_GET['view'], time()]);
      $s->fetchColumn() ? out(['ok' => 1, 'op' => 'view']) : out(['ok' => 0], 403);
    }
    $s = $db->prepare('SELECT op FROM tickets WHERE t=? AND vkey=? AND exp > ?'); $s->execute([(string)$_GET['check'], (string)($_GET['key'] ?? ''), time()]);
    $op = $s->fetchColumn();
    $op ? out(['ok' => 1, 'op' => $op]) : out(['ok' => 0], 403);
  }

  $pid = guide_login();
  if (!guide_can_feed($pid)) fail('Coming soon: the Trip Feed isn\'t open for you yet.', 403);
  $db = social_db();
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

  if ($method !== 'POST' && isset($_GET['f'])) {
    $s = $db->prepare("SELECT * FROM posts WHERE id=? AND state != 'gone'"); $s->execute([(int)$_GET['f']]);
    $r = $s->fetch();
    $small = $r && (!empty($_GET['s']) || $r['kind'] === 'video');
    $file = $r ? social_dir() . '/' . $r['id'] . ($small && $r['prev'] ? '-s' : '') . '.jpg' : '';
    if (!$r || !is_file($file)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: private, max-age=86400');
    if (!empty($_GET['dl'])) header('Content-Disposition: attachment; filename="' . preg_replace('/\.[A-Za-z0-9]+$/', '', $r['name']) . '.jpg"');
    readfile($file);
    exit;
  }

  if ($method !== 'POST') {
    $s = $db->prepare('SELECT t FROM views WHERE pid=? AND exp > ? ORDER BY exp DESC LIMIT 1'); $s->execute([$pid, time() + 7 * 86400]);
    $view = $s->fetchColumn();
    if (!$view) {
      $view = token();
      $db->prepare('DELETE FROM views WHERE exp < ?')->execute([time()]);
      $db->prepare('INSERT INTO views (t, pid, exp) VALUES (?, ?, ?)')->execute([$view, $pid, time() + 30 * 86400]);
    }
    $posts = group_posts($db->query("SELECT * FROM posts WHERE state='live' ORDER BY grp DESC, id ASC")->fetchAll());
    out(['posts' => $posts, 'view' => $view, 'media' => MEDIA_URL]);
  }

  /* raw JPEG uploads: the photo itself, or a smaller copy / a video's cover picture */
  if (isset($_GET['up'])) {
    $r = own_post($db, (int)($_GET['id'] ?? 0), $pid);
    $main = $_GET['up'] === 'main';
    if ($main && $r['kind'] !== 'photo') fail('Bad request.');
    $raw = file_get_contents('php://input', false, null, 0, PHOTO_MAX + 1);
    if ($raw === false || $raw === '') fail('Nothing came through. Try again.');
    if (strlen($raw) > PHOTO_MAX) fail('That photo is too big.');
    $info = @getimagesizefromstring($raw);
    if (!$info || $info['mime'] !== 'image/jpeg') fail('Please send a JPG picture.');
    $file = social_dir() . '/' . $r['id'] . ($main ? '' : '-s') . '.jpg';
    if (file_put_contents($file, $raw, LOCK_EX) === false) fail('Could not save.', 500);
    @chmod($file, 0600);
    if ($main) $db->prepare('UPDATE posts SET size=?, w=?, h=? WHERE id=?')->execute([strlen($raw), $info[0], $info[1], $r['id']]);
    else $db->prepare('UPDATE posts SET prev=1 WHERE id=?')->execute([$r['id']]);
    out(['ok' => 1]);
  }

  $b = json_decode(file_get_contents('php://input') ?: '', true);
  if (!is_array($b)) fail('Bad request.');
  $act = (string)($b['act'] ?? '');

  if ($act === 'start') {
    $kind = ($b['kind'] ?? '') === 'video' ? 'video' : 'photo';
    $size = (int)($b['size'] ?? 0);
    if ($kind === 'video' && ($size <= 0 || $size > VIDEO_MAX)) fail('Videos can be up to 4 GB.');
    $name = clean_name((string)($b['name'] ?? ''));
    $cap = clean_cap($b['cap'] ?? '');
    $tags = clean_tags($b['tags'] ?? null);
    /* the second, third... photo of a post joins the first one's post */
    $grp = (int)($b['grp'] ?? 0);
    if ($grp) {
      $s = $db->prepare('SELECT pid FROM posts WHERE grp=? LIMIT 1'); $s->execute([$grp]);
      if ($s->fetchColumn() !== $pid) fail('Bad request.');
      $s = $db->prepare("SELECT COUNT(*) FROM posts WHERE grp=? AND state != 'gone'"); $s->execute([$grp]);
      if ((int)$s->fetchColumn() >= 20) fail('A post can have up to 20 photos and videos.');
    }
    $vkey = null;
    if ($kind === 'video') {
      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      if (!preg_match('/^[a-z0-9]{1,5}$/', $ext)) $ext = 'mp4';
      $vkey = 'v/' . bin2hex(random_bytes(12)) . '.' . $ext;
    }
    $num = fn($k) => isset($b[$k]) && is_numeric($b[$k]) ? $b[$k] + 0 : null;
    $db->prepare('INSERT INTO posts (pid, kind, vkey, name, size, w, h, dur, cap, tags, grp, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$pid, $kind, $vkey, $name, $size, $num('w'), $num('h'), $num('dur'), $cap, $tags, $grp ?: null, time()]);
    $id = (int)$db->lastInsertId();
    if (!$grp) { $grp = $id; $db->prepare('UPDATE posts SET grp=? WHERE id=?')->execute([$id, $id]); }
    out($kind === 'video' ? ['id' => $id, 'grp' => $grp, 'key' => $vkey, 'ticket' => ticket($db, $vkey, 'put')] : ['id' => $id, 'grp' => $grp]);
  }
  if ($act === 'done') {
    $r = own_post($db, (int)($b['id'] ?? 0), $pid);
    if ($r['kind'] === 'photo' && !is_file(social_dir() . '/' . $r['id'] . '.jpg')) fail('The photo did not upload. Try again.');
    if (!empty($b['hold'])) { $db->prepare("UPDATE posts SET state='ready' WHERE id=? AND state='up'")->execute([$r['id']]); out(['ok' => 1]); }
    $db->prepare("UPDATE posts SET state='live' WHERE id=?")->execute([$r['id']]);
    out(['post' => one_post($db, (int)$r['grp'])]);
  }
  if ($act === 'publish') {
    $grp = (int)($b['grp'] ?? 0);
    own_group($db, $grp, $pid);
    $db->prepare("UPDATE posts SET state='live' WHERE grp=? AND state='ready'")->execute([$grp]);
    $post = one_post($db, $grp);
    if (!$post) fail('None of the photos uploaded. Try again.');
    out(['post' => $post]);
  }
  if ($act === 'edit') {
    $grp = (int)($b['id'] ?? 0);
    $rows = array_values(array_filter(own_group($db, $grp, $pid), fn($r) => $r['state'] === 'live'));
    $drop = array_map('intval', is_array($b['drop'] ?? null) ? $b['drop'] : []);
    $gone = array_values(array_filter($rows, fn($r) => in_array((int)$r['id'], $drop, true)));
    if ($rows && count($gone) === count($rows)) fail('A post needs at least one photo. To remove all of them, delete the post.');
    $db->prepare('UPDATE posts SET cap=?, tags=? WHERE grp=?')->execute([clean_cap($b['cap'] ?? ''), clean_tags($b['tags'] ?? null), $grp]);
    $vids = remove_rows($db, $gone);
    out(['post' => one_post($db, $grp), 'vids' => $vids]);
  }
  if ($act === 'drop') {
    $r = own_post($db, (int)($b['id'] ?? 0), $pid);
    out(['ok' => 1, 'vids' => remove_rows($db, [$r])]);
  }
  if ($act === 'del') {
    $rows = own_group($db, (int)($b['id'] ?? 0), $pid);
    out(['ok' => 1, 'vids' => remove_rows($db, $rows)]);
  }
  fail('Bad request.');
} catch (Throwable $e) {
  fail('Server problem. Try again.', 500);
}
