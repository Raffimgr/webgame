<?php
/* The to-do lists (plan.html): what the group needs to buy, book or do for a trip or event, who is on it, and notes.
   Kept in ridge-data/plan.sqlite, logged-in players only. Anyone can add, edit, take or tick off an item;
   whoever added it (or the admin) can delete it.
   GET  plan.php -> {lists:[{id,name}], items:[...]}
   POST {act:"list", name}                                            -> {list}  a new list (one per trip or event)
   POST {act:"save", id?, list, title, kind, notes, who, due}         -> {item}  add, or edit when id is given
   POST {act:"done", id, done}                                        -> {item}
   POST {act:"del", id}                                               -> {ok}    (POSTs need the X-RG header) */

require __DIR__ . '/guide_lib.php';

const KINDS = ['buy', 'book', 'do'];

function plan_db(): PDO {
  $f = data_dir() . '/plan.sqlite';
  $pdo = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  $pdo->exec('PRAGMA busy_timeout=4000');
  $pdo->exec('CREATE TABLE IF NOT EXISTS lists (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, at INTEGER NOT NULL)');
  $pdo->exec("CREATE TABLE IF NOT EXISTS items (id INTEGER PRIMARY KEY AUTOINCREMENT, list INTEGER NOT NULL, title TEXT NOT NULL, kind TEXT NOT NULL,
    notes TEXT NOT NULL DEFAULT '', who TEXT NOT NULL DEFAULT '', due TEXT NOT NULL DEFAULT '', done INTEGER NOT NULL DEFAULT 0,
    done_by TEXT NOT NULL DEFAULT '', done_at INTEGER NOT NULL DEFAULT 0, by TEXT NOT NULL, at INTEGER NOT NULL)");
  if (!$pdo->query('SELECT COUNT(*) FROM lists')->fetchColumn()) $pdo->prepare('INSERT INTO lists (name, at) VALUES (?, ?)')->execute(['Shenandoah trip', time()]);
  @chmod($f, 0600);
  return $pdo;
}
function text($s, int $max): string { return mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$s) ?? ''), 0, $max); }
function item_row(array $r): array {
  return ['id' => (int)$r['id'], 'list' => (int)$r['list'], 'title' => $r['title'], 'kind' => $r['kind'], 'notes' => $r['notes'], 'who' => $r['who'],
    'due' => $r['due'], 'done' => (int)$r['done'], 'done_by' => $r['done_by'], 'done_at' => (int)$r['done_at'], 'by' => $r['by'], 'at' => (int)$r['at']];
}
function get_item(PDO $db, int $id): array {
  $s = $db->prepare('SELECT * FROM items WHERE id=?'); $s->execute([$id]);
  $r = $s->fetch();
  if (!$r) fail('That item is gone.', 404);
  return $r;
}
function is_player(string $id): bool {
  $pdo = new PDO('sqlite:' . data_dir() . '/ridge.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $s = $pdo->prepare('SELECT 1 FROM players WHERE id=? AND active=1'); $s->execute([$id]);
  return (bool)$s->fetchColumn();
}

try {
  $pid = guide_login();
  $db = plan_db();
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    out(['lists' => array_map(fn($l) => ['id' => (int)$l['id'], 'name' => $l['name']], $db->query('SELECT id, name FROM lists ORDER BY id')->fetchAll()),
         'items' => array_map('item_row', $db->query('SELECT * FROM items ORDER BY id')->fetchAll())]);
  }
  $b = json_decode(file_get_contents('php://input') ?: '', true);
  if (!is_array($b)) fail('Bad request.');
  $act = (string)($b['act'] ?? '');

  if ($act === 'list') {
    $name = text($b['name'] ?? '', 60);
    if ($name === '') fail('Give the list a name.');
    $db->prepare('INSERT INTO lists (name, at) VALUES (?, ?)')->execute([$name, time()]);
    out(['list' => ['id' => (int)$db->lastInsertId(), 'name' => $name]]);
  }
  if ($act === 'save') {
    $title = text($b['title'] ?? '', 120);
    if ($title === '') fail('Write what needs doing.');
    $kind = in_array($b['kind'] ?? '', KINDS, true) ? $b['kind'] : 'do';
    $notes = text($b['notes'] ?? '', 1000);
    $who = (string)($b['who'] ?? '');
    if ($who !== '' && !is_player($who)) fail('Pick someone from the group.');
    $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($b['due'] ?? '')) ? $b['due'] : '';
    if (!empty($b['id'])) {
      $r = get_item($db, (int)$b['id']);
      $db->prepare('UPDATE items SET title=?, kind=?, notes=?, who=?, due=? WHERE id=?')->execute([$title, $kind, $notes, $who, $due, $r['id']]);
      out(['item' => item_row(get_item($db, (int)$r['id']))]);
    }
    $list = (int)($b['list'] ?? 0);
    $s = $db->prepare('SELECT 1 FROM lists WHERE id=?'); $s->execute([$list]);
    if (!$s->fetchColumn()) fail('That list is gone.', 404);
    $db->prepare('INSERT INTO items (list, title, kind, notes, who, due, by, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$list, $title, $kind, $notes, $who, $due, $pid, time()]);
    out(['item' => item_row(get_item($db, (int)$db->lastInsertId()))]);
  }
  if ($act === 'done') {
    $r = get_item($db, (int)($b['id'] ?? 0));
    $done = !empty($b['done']);
    $db->prepare('UPDATE items SET done=?, done_by=?, done_at=? WHERE id=?')->execute([$done ? 1 : 0, $done ? $pid : '', $done ? time() : 0, $r['id']]);
    out(['item' => item_row(get_item($db, (int)$r['id']))]);
  }
  if ($act === 'del') {
    $r = get_item($db, (int)($b['id'] ?? 0));
    if ($r['by'] !== $pid && !guide_is_admin($pid)) fail('Only the person who added it (or the admin) can delete it.', 403);
    $db->prepare('DELETE FROM items WHERE id=?')->execute([$r['id']]);
    out(['ok' => 1]);
  }
  fail('Bad request.');
} catch (Throwable $e) {
  fail('Server problem. Try again.', 500);
}
