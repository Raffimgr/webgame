<?php
/* The group chat (chat.html) where everyone talks together, and the unread count for the Chat button in the bottom bar.
   Kept in ridge-data/chat.sqlite, logged-in players only. Anyone can send and heart messages; you (or the admin) can delete yours.
   GET  chat.php?rev=N             -> {rev, seen:{player: last read id}}, plus msgs:[the last 300] when something changed since N
   GET  chat.php?badge=1[&me=1]    -> {n} messages from others you haven't read yet (+ me: your name and picture for the bar)
   POST {act:"say", body}          -> {rev, msg}
   POST {act:"heart", id}          -> {rev}   your heart on a message, on or off
   POST {act:"del", id}            -> {rev}
   POST {act:"seen", id}           -> {ok}    you have read everything up to id   (POSTs need the X-RG header) */

require __DIR__ . '/guide_lib.php';

const KEEP = 300;

function chat_db(): PDO {
  $f = data_dir() . '/chat.sqlite';
  $pdo = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  $pdo->exec('PRAGMA busy_timeout=4000');
  $pdo->exec('CREATE TABLE IF NOT EXISTS msgs (id INTEGER PRIMARY KEY AUTOINCREMENT, pid TEXT NOT NULL, body TEXT NOT NULL, at INTEGER NOT NULL)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS hearts (mid INTEGER NOT NULL, pid TEXT NOT NULL, PRIMARY KEY (mid, pid))');
  $pdo->exec('CREATE TABLE IF NOT EXISTS seen (pid TEXT PRIMARY KEY, mid INTEGER NOT NULL)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v INTEGER NOT NULL)');
  @chmod($f, 0600);
  return $pdo;
}
function rev(PDO $db): int { return (int)$db->query("SELECT v FROM meta WHERE k='rev'")->fetchColumn(); }
function bump(PDO $db): int {
  $db->exec("INSERT OR IGNORE INTO meta (k, v) VALUES ('rev', 0)");
  $db->exec("UPDATE meta SET v = v + 1 WHERE k='rev'");
  return rev($db);
}
function clean($s, int $max): string { return mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$s) ?? ''), 0, $max); }
function get_msg(PDO $db, int $id): array {
  $s = $db->prepare('SELECT * FROM msgs WHERE id=?'); $s->execute([$id]);
  $r = $s->fetch();
  if (!$r) fail('That message is gone.', 404);
  return $r;
}
function msg_row(array $r, array $hearts): array {
  return ['id' => (int)$r['id'], 'by' => $r['pid'], 'body' => $r['body'], 'at' => (int)$r['at'], 'hearts' => $hearts[(int)$r['id']] ?? []];
}

try {
  $pid = guide_login();
  $db = chat_db();
  $can = guide_can_chat($pid);
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if (isset($_GET['badge'])) {
      $s = $db->prepare('SELECT COUNT(*) FROM msgs WHERE pid<>? AND id > COALESCE((SELECT mid FROM seen WHERE pid=?), 0)'); $s->execute([$pid, $pid]);
      $res = ['n' => $can ? (int)$s->fetchColumn() : 0];
      if (isset($_GET['me'])) {
        $pdo = new PDO('sqlite:' . data_dir() . '/ridge.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $s = $pdo->prepare('SELECT id, name, short, color, avatar FROM players WHERE id=?'); $s->execute([$pid]);
        $res['me'] = $s->fetch() ?: null;
      }
      out($res);
    }
    if (!$can) fail('Coming soon: the chat isn\'t open for you yet.', 403);
    $rev = rev($db);
    $seen = [];
    foreach ($db->query('SELECT pid, mid FROM seen')->fetchAll() as $r) $seen[$r['pid']] = (int)$r['mid'];
    if (isset($_GET['rev']) && (int)$_GET['rev'] === $rev) out(['rev' => $rev, 'seen' => (object)$seen]);
    $rows = array_reverse($db->query('SELECT * FROM msgs ORDER BY id DESC LIMIT ' . KEEP)->fetchAll());
    $hearts = [];
    if ($rows) {
      $s = $db->prepare('SELECT mid, pid FROM hearts WHERE mid >= ? ORDER BY rowid'); $s->execute([(int)$rows[0]['id']]);
      foreach ($s->fetchAll() as $h) $hearts[(int)$h['mid']][] = $h['pid'];
    }
    out(['rev' => $rev, 'msgs' => array_map(fn($r) => msg_row($r, $hearts), $rows), 'seen' => (object)$seen]);
  }
  if (!$can) fail('Coming soon: the chat isn\'t open for you yet.', 403);
  $b = json_decode(file_get_contents('php://input') ?: '', true);
  if (!is_array($b)) fail('Bad request.');
  $act = (string)($b['act'] ?? '');

  if ($act === 'say') {
    $body = clean($b['body'] ?? '', 1000);
    if ($body === '') fail('Write something first.');
    $db->prepare('INSERT INTO msgs (pid, body, at) VALUES (?, ?, ?)')->execute([$pid, $body, time()]);
    $id = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO seen (pid, mid) VALUES (?, ?) ON CONFLICT(pid) DO UPDATE SET mid=excluded.mid')->execute([$pid, $id]);
    out(['rev' => bump($db), 'msg' => msg_row(get_msg($db, $id), [])]);
  }
  if ($act === 'heart') {
    $m = get_msg($db, (int)($b['id'] ?? 0));
    $s = $db->prepare('DELETE FROM hearts WHERE mid=? AND pid=?'); $s->execute([$m['id'], $pid]);
    if (!$s->rowCount()) $db->prepare('INSERT INTO hearts (mid, pid) VALUES (?, ?)')->execute([$m['id'], $pid]);
    out(['rev' => bump($db)]);
  }
  if ($act === 'del') {
    $m = get_msg($db, (int)($b['id'] ?? 0));
    if ($m['pid'] !== $pid && !guide_is_admin($pid)) fail('You can only delete your own messages.', 403);
    $db->prepare('DELETE FROM hearts WHERE mid=?')->execute([$m['id']]);
    $db->prepare('DELETE FROM msgs WHERE id=?')->execute([$m['id']]);
    out(['rev' => bump($db)]);
  }
  if ($act === 'seen') {
    $id = (int)($b['id'] ?? 0);
    $db->prepare('INSERT INTO seen (pid, mid) VALUES (?, ?) ON CONFLICT(pid) DO UPDATE SET mid=MAX(mid, excluded.mid)')->execute([$pid, $id]);
    out(['ok' => 1]);
  }
  fail('Bad request.');
} catch (Throwable $e) {
  fail('Server problem. Try again.', 500);
}
