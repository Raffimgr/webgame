<?php
/* Likes and comments on the guide (places, places to eat, history, videos, photos), kept in ridge-data/talk.sqlite
   and shown only to logged-in players.
   GET talk.php -> {items:{key:{likes:[player ids], comments:[{id,by,body,at}]}}}
   POST {act:"like", item} turns your like on or off.   POST {act:"say", item, body} adds a comment.
   POST {act:"del", id} removes a comment (whoever wrote it, or the admin). POSTs need the X-RG header; each answers with the updated item. */

require __DIR__ . '/guide_lib.php';

function talk_db(): PDO {
  $f = data_dir() . '/talk.sqlite';
  $pdo = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  $pdo->exec('PRAGMA busy_timeout=4000');
  $pdo->exec('CREATE TABLE IF NOT EXISTS likes (item TEXT NOT NULL, pid TEXT NOT NULL, at INTEGER NOT NULL, PRIMARY KEY (item, pid))');
  $pdo->exec('CREATE TABLE IF NOT EXISTS comments (id INTEGER PRIMARY KEY AUTOINCREMENT, item TEXT NOT NULL, pid TEXT NOT NULL, body TEXT NOT NULL, at INTEGER NOT NULL)');
  @chmod($f, 0600);
  return $pdo;
}
function clean_item($s): string { return preg_match('/^[a-z]{2,4}-[A-Za-z0-9_-]{1,60}$/', (string)$s) ? (string)$s : ''; }
function talk_item(PDO $db, string $item): array {
  $l = $db->prepare('SELECT pid FROM likes WHERE item=? ORDER BY at'); $l->execute([$item]);
  $c = $db->prepare('SELECT id, pid, body, at FROM comments WHERE item=? ORDER BY id'); $c->execute([$item]);
  $cs = array_map(fn($r) => ['id' => (int)$r['id'], 'by' => $r['pid'], 'body' => $r['body'], 'at' => (int)$r['at']], $c->fetchAll());
  return ['likes' => $l->fetchAll(PDO::FETCH_COLUMN), 'comments' => $cs];
}

try {
  $pid = guide_login();
  $db = talk_db();
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $items = [];
    foreach ($db->query('SELECT item, pid FROM likes ORDER BY at') as $r) $items[$r['item']]['likes'][] = $r['pid'];
    foreach ($db->query('SELECT id, item, pid, body, at FROM comments ORDER BY id') as $r)
      $items[$r['item']]['comments'][] = ['id' => (int)$r['id'], 'by' => $r['pid'], 'body' => $r['body'], 'at' => (int)$r['at']];
    foreach ($items as &$it) $it += ['likes' => [], 'comments' => []];
    out(['items' => (object)$items]);
  }
  $b = json_decode(file_get_contents('php://input') ?: '', true);
  if (!is_array($b)) fail('Bad request.');
  $act = (string)($b['act'] ?? '');
  if ($act === 'del') {
    $s = $db->prepare('SELECT item, pid FROM comments WHERE id=?'); $s->execute([(int)($b['id'] ?? 0)]);
    $row = $s->fetch();
    if (!$row) fail('That comment is already gone.', 404);
    if ($row['pid'] !== $pid && !guide_is_admin($pid)) fail('Only the person who wrote it (or the admin) can remove it.', 403);
    $db->prepare('DELETE FROM comments WHERE id=?')->execute([(int)$b['id']]);
    out(['item' => $row['item'], 'data' => talk_item($db, $row['item'])]);
  }
  $item = clean_item($b['item'] ?? '');
  if ($item === '') fail('Bad request.');
  if ($act === 'like') {
    $d = $db->prepare('DELETE FROM likes WHERE item=? AND pid=?'); $d->execute([$item, $pid]);
    if ($d->rowCount() === 0) $db->prepare('INSERT INTO likes (item, pid, at) VALUES (?, ?, ?)')->execute([$item, $pid, time()]);
  } elseif ($act === 'say') {
    $body = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)($b['body'] ?? '')) ?? '');
    if ($body === '') fail('Write something first.');
    $body = mb_substr($body, 0, 500);
    $db->prepare('INSERT INTO comments (item, pid, body, at) VALUES (?, ?, ?, ?)')->execute([$item, $pid, $body, time()]);
  } else fail('Bad request.');
  out(['item' => $item, 'data' => talk_item($db, $item)]);
} catch (Throwable $e) {
  fail('Server problem. Try again.', 500);
}
