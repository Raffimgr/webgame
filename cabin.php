<?php
/* The trip cabin's address, for the guide's map pin and "Directions to the cabin" button.
   Kept on the server (ridge-data/cabin.json), never in this public repo, and only given to logged-in players.
   GET cabin.php -> {address, ll}. POST {address, ll?} with the X-RG header saves it (any logged-in player). */

declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit; }
function fail(string $msg, int $code = 400): void { out(['error' => $msg], $code); }

// Same data folder and session as api.php.
function data_dir(): string {
  $env = getenv('RG_DATA');
  if ($env) return $env;
  $outside = dirname(__DIR__, 2) . '/ridge-data';
  if (is_dir($outside) && is_writable($outside)) return $outside;
  return __DIR__ . '/data';
}

$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($post && ($_SERVER['HTTP_X_RG'] ?? '') !== '1') fail('Bad request.');

try {
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

  $file = "$dir/cabin.json";
  if (!$post) {
    $c = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    out(['address' => $c['address'] ?? null, 'll' => $c['ll'] ?? null]);
  }
  $b = json_decode(file_get_contents('php://input') ?: '', true);
  if (!is_array($b)) fail('Bad request.');
  $addr = mb_substr(trim((string)($b['address'] ?? '')), 0, 200);
  $ll = null;
  if (isset($b['ll']) && is_array($b['ll']) && count($b['ll']) === 2) {
    [$lat, $lng] = array_map('floatval', $b['ll']);
    if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && ($lat || $lng)) $ll = [round($lat, 6), round($lng, 6)];
  }
  if ($addr === '') { @unlink($file); out(['address' => null, 'll' => null]); }
  if (file_put_contents($file, json_encode(['address' => $addr, 'll' => $ll, 'by' => $pid, 'at' => date('c')]), LOCK_EX) === false) fail('Could not save.', 500);
  @chmod($file, 0600);
  out(['address' => $addr, 'll' => $ll]);
} catch (Throwable $e) {
  fail('Server problem. Try again.', 500);
}
