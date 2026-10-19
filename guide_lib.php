<?php
/* Shared by the guide's private files (cabin.php, tripmap.php): JSON replies, the data folder and the logged-in check.
   Uses the same data folder and session cookie as api.php, so a player logged in to the games is logged in here. */

declare(strict_types=1);
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }
ini_set('display_errors', '0');

function out($data, int $code = 200): void { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit; }
function fail(string $msg, int $code = 400): void { out(['error' => $msg], $code); }

function data_dir(): string {
  $env = getenv('RG_DATA');
  if ($env) return $env;
  $outside = dirname(__DIR__, 2) . '/ridge-data';
  if (is_dir($outside) && is_writable($outside)) return $outside;
  return __DIR__ . '/data';
}

/* Returns the logged-in player's id, or answers 401. POSTs must carry the X-RG header. */
function guide_login(): string {
  header('Cache-Control: no-store');
  header('X-Content-Type-Options: nosniff');
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_SERVER['HTTP_X_RG'] ?? '') !== '1') fail('Bad request.');
  $dir = data_dir();
  if (!is_dir($dir)) fail('Not set up yet.', 404);
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443)
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on');
  $cookiePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
  session_name('rgsess');
  session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 60, 'path' => $cookiePath, 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
  ini_set('session.use_strict_mode', '1');
  ini_set('session.use_only_cookies', '1');
  ini_set('session.gc_probability', '0');
  session_save_path($dir);
  if (!session_start(['read_and_close' => true])) fail('Server problem. Try again.', 500);
  $pid = $_SESSION['pid'] ?? null;
  if (!$pid) fail('Please log in.', 401);
  $pdo = new PDO('sqlite:' . $dir . '/ridge.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $s = $pdo->prepare('SELECT 1 FROM players WHERE id=? AND active=1'); $s->execute([$pid]);
  if (!$s->fetchColumn()) fail('Please log in.', 401);
  return (string)$pid;
}
