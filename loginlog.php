<?php
/* Ridge Games: the login log in Admin > Settings (Sako asked, 2026-10-21): who logged in or opened the app, and when.
   Kept in ridge-data/logins.sqlite, outside the web folder; only admins can read it (api.php?a=login_log).
   Rows: login (typed their password), open (came back while still logged in; at most one per 30 minutes per member), logout,
   fail (typed a wrong password) and nopass (tried to log in before the admin set them a password); both added 2026-10-22, Sako.
   Kept for 90 days. Logging never stops anyone from logging in: every error here is swallowed. Included by api.php.
   Logins weren't recorded before this log went live (2026-10-21), so log_earlier() rebuilds when each member was using the app
   before then from what they did: chat messages, Feed posts, likes, comments, To-do changes and online games they started.
   2026-10-22 (Sako: Yazan read the chat but never showed up): a page left open on a phone never loads again, so "open" rows now also
   come from what an open page keeps asking the server while it's on screen (the bell and chat checks, Feed, To-do): log_active(). */

declare(strict_types=1);
if (get_included_files()[0] === __FILE__) { http_response_code(404); exit; } // included by api.php and guide_lib.php only

const LOG_KEEP_DAYS = 90;
const LOG_VISIT_GAP = 1800; // a new "opened the app" row only after 30 quiet minutes

function log_db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $f = data_dir() . '/logins.sqlite';
  $pdo = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  $pdo->exec('PRAGMA busy_timeout=3000');
  $pdo->exec('CREATE TABLE IF NOT EXISTS log (id INTEGER PRIMARY KEY AUTOINCREMENT, pid TEXT NOT NULL, kind TEXT NOT NULL, at INTEGER NOT NULL, device TEXT NOT NULL DEFAULT "")');
  $pdo->exec('CREATE INDEX IF NOT EXISTS log_pid_at ON log (pid, at)');
  @chmod($f, 0600);
  return $pdo;
}

/* A short name for the device from the browser's User-Agent. iPads ask for the desktop site, so they look like a Mac. */
function log_device(): string {
  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
  if (stripos($ua, 'iPhone') !== false) return 'iPhone';
  if (stripos($ua, 'iPad') !== false) return 'iPad';
  if (stripos($ua, 'Android') !== false) return stripos($ua, 'Mobile') !== false ? 'Android phone' : 'Android tablet';
  if (stripos($ua, 'Macintosh') !== false) return 'iPad or Mac';
  if (stripos($ua, 'Windows') !== false) return 'Windows computer';
  if (stripos($ua, 'CrOS') !== false) return 'Chromebook';
  return $ua === '' ? '' : 'Other device';
}

function log_add(string $pid, string $kind): void {
  try {
    $db = log_db();
    $db->prepare('INSERT INTO log (pid, kind, at, device) VALUES (?, ?, ?, ?)')->execute([$pid, $kind, time(), log_device()]);
    if (random_int(1, 50) === 1) $db->prepare('DELETE FROM log WHERE at < ?')->execute([time() - LOG_KEEP_DAYS * 86400]);
  } catch (Throwable $e) {}
}

/* Called when a logged-in member loads a page (api.php?a=state). */
function log_visit(string $pid): void {
  try {
    $s = log_db()->prepare('SELECT MAX(at) FROM log WHERE pid=?'); $s->execute([$pid]);
    if (time() - (int)$s->fetchColumn() >= LOG_VISIT_GAP) log_add($pid, 'open');
  } catch (Throwable $e) {}
}

/* Called on the requests an open page makes by itself while it's on screen. The Score screen polls all day, so it only counts when it loads. */
function log_active(string $pid): void { if ($pid !== 'screen') log_visit($pid); }

function log_list(): array {
  try {
    $s = log_db()->prepare('SELECT pid, kind, at, device FROM log WHERE at >= ? ORDER BY at DESC, id DESC LIMIT 3000');
    $s->execute([time() - LOG_KEEP_DAYS * 86400]);
    return array_map(fn($r) => ['pid' => $r['pid'], 'kind' => $r['kind'], 'at' => (int)$r['at'], 'device' => $r['device']], $s->fetchAll());
  } catch (Throwable $e) { return []; }
}

/* Activity from before the log's first row, last 90 days, in hourly buckets: pid, kind, at (first), last, n.
   Uses nt_open() from notify.php, which api.php includes too. */
function log_earlier(): array {
  try { $before = (int)log_db()->query('SELECT MIN(at) FROM log')->fetchColumn() ?: time(); } catch (Throwable $e) { $before = time(); }
  $from = time() - LOG_KEEP_DAYS * 86400; $rows = [];
  $add = function (?PDO $db, string $kind, string $sql) use (&$rows, $from, $before) {
    if (!$db) return;
    try { $s = $db->prepare($sql); $s->execute([$from, $before]); foreach ($s->fetchAll(PDO::FETCH_NUM) as [$pid, $at]) if ($pid !== '') $rows[] = [(string)$pid, $kind, (int)$at]; } catch (Throwable $e) {}
  };
  $add(nt_open('chat'), 'chat', 'SELECT pid, at FROM msgs WHERE at>=? AND at<?');
  $add(nt_open('social'), 'feed', 'SELECT pid, MIN(at) FROM posts WHERE at>=? AND at<? GROUP BY grp');
  $add($t = nt_open('talk'), 'like', 'SELECT pid, at FROM likes WHERE at>=? AND at<?');
  $add($t, 'comment', 'SELECT pid, at FROM comments WHERE at>=? AND at<?');
  $add($p = nt_open('plan'), 'todo', 'SELECT "by", at FROM items WHERE at>=? AND at<?');
  // items imported as already done carry done_at = at and the assignee as done_by, so they say nothing about that person
  $add($p, 'todo', "SELECT done_by, done_at FROM items WHERE done_at>=? AND done_at<? AND done_by<>'' AND done_at<>at");
  $add(db(), 'game', 'SELECT host, created_at FROM rooms WHERE created_at>=? AND created_at<?');
  $b = [];
  foreach ($rows as [$pid, $kind, $at]) {
    $k = $pid . '|' . $kind . '|' . intdiv($at, 3600);
    if (!isset($b[$k])) $b[$k] = ['pid' => $pid, 'kind' => $kind, 'at' => $at, 'last' => $at, 'n' => 0];
    $b[$k]['at'] = min($b[$k]['at'], $at); $b[$k]['last'] = max($b[$k]['last'], $at); $b[$k]['n']++;
  }
  $b = array_values($b); usort($b, fn($x, $y) => $y['last'] <=> $x['last']);
  return ['before' => $before, 'rows' => $b];
}
