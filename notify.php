<?php
/* Ridge Games: the bell next to your name on every page (rg.js; Sako asked, 2026-10-21).
   It gathers what is new for you from the other parts of the site, last 14 days:
     chat     messages from others you haven't read (chat.sqlite)
     post     a new Trip Feed post by someone else; tag when they tagged you in it (social.sqlite)
     like     likes on your Feed posts; comment: comments on your Feed posts (talk.sqlite, keys po-<post id>)
     invite   a game someone asked you to join that is still waiting (api.php my_invites)
   "New" means after you last opened the bell (ridge-data/notify.sqlite); chat also needs to be unread.
   Feed and Chat items follow Admin > Settings access. Included by api.php. */

declare(strict_types=1);
if (!defined('RG_API')) { http_response_code(404); exit; }

const NOTIFY_DAYS = 14;

function nt_open(string $name): ?PDO {
  $f = data_dir() . '/' . $name . '.sqlite';
  if (!is_file($f)) return null;
  $p = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  $p->exec('PRAGMA busy_timeout=2000');
  return $p;
}
function nt_db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $f = data_dir() . '/notify.sqlite';
  $pdo = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  $pdo->exec('PRAGMA busy_timeout=3000');
  $pdo->exec('CREATE TABLE IF NOT EXISTS seen (pid TEXT PRIMARY KEY, at INTEGER NOT NULL)');
  @chmod($f, 0600);
  return $pdo;
}
function nt_seen(string $pid): int {
  $s = nt_db()->prepare('SELECT at FROM seen WHERE pid=?'); $s->execute([$pid]);
  return (int)$s->fetchColumn();
}
function notify_mark(string $pid): void {
  nt_db()->prepare('INSERT INTO seen (pid, at) VALUES (?, ?) ON CONFLICT(pid) DO UPDATE SET at=excluded.at')->execute([$pid, time()]);
}
function nt_short(string $s, int $max = 70): string { $s = trim(preg_replace('/\s+/u', ' ', $s) ?? ''); return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s; }

/* Everything for the bell, newest first. Each item: kind, by (ids, newest first), at, text, href, new (how many of it are new). */
function notify_items(array $me): array {
  $pid = $me['id']; $from = time() - NOTIFY_DAYS * 86400; $seen = nt_seen($pid); $items = [];
  $acc = access_conf(); $admin = (int)$me['is_admin'] === 1;
  $feed = $admin || !in_array($pid, $acc['nofeed'], true);
  $chat = $admin || !in_array($pid, $acc['nochat'], true);
  if ((int)($me['screen'] ?? 0)) return ['items' => [], 'n' => 0, 'chat' => 0, 'seen' => $seen];

  $unread = 0;
  if ($chat) try { if ($db = nt_open('chat')) {
    $s = $db->prepare('SELECT pid, at FROM msgs WHERE pid<>? AND at>=? AND id > COALESCE((SELECT mid FROM seen WHERE pid=?), 0) ORDER BY id DESC');
    $s->execute([$pid, $from, $pid]); $rows = $s->fetchAll();
    $unread = count($rows);
    if ($rows) $items[] = ['kind' => 'chat', 'by' => array_values(array_unique(array_column($rows, 'pid'))), 'at' => (int)$rows[0]['at'], 'count' => $unread,
      'href' => 'chat.html', 'new' => count(array_filter($rows, fn($r) => (int)$r['at'] > $seen))];
  } } catch (Throwable $e) {}

  if ($feed) try { if ($db = nt_open('social')) {
    $mine = [];
    foreach ($db->query("SELECT grp, pid, MIN(at) at, MAX(cap) cap, MAX(tags) tags, COUNT(*) n, SUM(kind='video') v FROM posts WHERE state='live' GROUP BY grp")->fetchAll() as $p) {
      $g = (int)$p['grp'];
      if ($p['pid'] === $pid) { $mine[$g] = $p; continue; }
      if ((int)$p['at'] < $from) continue;
      $tagged = in_array($pid, $p['tags'] === '' ? [] : explode(',', $p['tags']), true);
      $what = (int)$p['v'] === (int)$p['n'] ? ((int)$p['n'] > 1 ? $p['n'] . ' videos' : 'a video') : ((int)$p['v'] ? $p['n'] . ' photos and videos' : ((int)$p['n'] > 1 ? $p['n'] . ' photos' : 'a photo'));
      $items[] = ['kind' => $tagged ? 'tag' : 'post', 'by' => [$p['pid']], 'at' => (int)$p['at'], 'what' => $what, 'text' => nt_short((string)$p['cap']),
        'href' => 'social.html#po-' . $g, 'new' => (int)$p['at'] > $seen ? 1 : 0];
    }
    if ($mine && ($t = nt_open('talk'))) {
      $keys = array_map(fn($g) => 'po-' . $g, array_keys($mine)); $q = implode(',', array_fill(0, count($keys), '?'));
      $s = $t->prepare("SELECT item, pid, at FROM likes WHERE item IN ($q) AND pid<>? AND at>=? ORDER BY at DESC"); $s->execute([...$keys, $pid, $from]);
      $likes = [];
      foreach ($s->fetchAll() as $l) $likes[$l['item']][] = $l;
      foreach ($likes as $k => $ls) $items[] = ['kind' => 'like', 'by' => array_values(array_unique(array_column($ls, 'pid'))), 'at' => (int)$ls[0]['at'],
        'text' => nt_short((string)$mine[(int)substr($k, 3)]['cap']), 'href' => 'social.html#' . $k, 'new' => count(array_filter($ls, fn($l) => (int)$l['at'] > $seen))];
      $s = $t->prepare("SELECT item, pid, body, at FROM comments WHERE item IN ($q) AND pid<>? AND at>=? ORDER BY at DESC LIMIT 40"); $s->execute([...$keys, $pid, $from]);
      foreach ($s->fetchAll() as $c) $items[] = ['kind' => 'comment', 'by' => [$c['pid']], 'at' => (int)$c['at'], 'text' => nt_short((string)$c['body']),
        'href' => 'social.html#' . $c['item'], 'new' => (int)$c['at'] > $seen ? 1 : 0];
    }
  } } catch (Throwable $e) {}

  try {
    foreach (my_invites($me) as $r) $items[] = ['kind' => 'invite', 'by' => [$r['host']], 'at' => $r['at'], 'text' => $r['label'],
      'href' => ($r['game'] === 'poker' ? 'online.html' : ($r['game'] === 'crown' ? 'map.html' : 'play.html')) . '?room=' . $r['id'], 'new' => $r['at'] > $seen ? 1 : 0];
  } catch (Throwable $e) {}

  usort($items, fn($a, $b) => $b['at'] <=> $a['at']);
  $items = array_slice($items, 0, 40);
  return ['items' => $items, 'n' => array_sum(array_column($items, 'new')), 'chat' => $unread, 'seen' => $seen];
}
