<?php
/* The login wall (Sako, 2026-10-24: "every one should log in, no one should be able to access the website without a log in").
   .htaccess sends every request for a page, script, picture or other file in this folder through here (.php files check the
   login themselves). A logged-in member gets the file. Anyone else gets the login screen instead of a page, and nothing at all
   for the other files, except the few the login screen and the home-screen icon need: rg.js (draws the login screen), the icons,
   the manifest and sw.js (the phone checks it for updates without a login). */

declare(strict_types=1);
require __DIR__ . '/guide_lib.php';

const GATE_TYPES = ['html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8',
  'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
  'ico' => 'image/x-icon', 'webmanifest' => 'application/manifest+json', 'json' => 'application/json; charset=utf-8',
  'mp3' => 'audio/mpeg', 'woff2' => 'font/woff2'];
const GATE_OPEN = ['rg.js', 'sw.js', 'manifest.webmanifest'];

function gate_stop(int $code): void { http_response_code($code); header('Cache-Control: no-store'); header('Content-Type: text/plain; charset=utf-8'); echo $code === 404 ? 'Not found.' : 'Please log in.'; exit; }

$f = (string)($_GET['rgf'] ?? '');
if ($f === '') $f = 'index.html';
// only the site's own files: no hidden files, no climbing out of the folder, no data or Worker folders, only the kinds listed above
if (!preg_match('~^(?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*[A-Za-z0-9_-][A-Za-z0-9._-]*$~', $f) || str_contains($f, '..')
  || preg_match('~^(data|cloudflare)/~', $f)) gate_stop(404);
$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
$path = realpath(__DIR__ . '/' . $f);
if (!isset(GATE_TYPES[$ext]) || $path === false || !is_file($path) || !str_starts_with($path, __DIR__ . DIRECTORY_SEPARATOR)) gate_stop(404);

header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Vary: Cookie');
header('X-LiteSpeed-Cache-Control: no-cache'); // the host's page cache must never keep a member's copy for someone else

$open = in_array($f, GATE_OPEN, true) || str_starts_with($f, 'icons/');
if (!$open && guide_pid() === null) {
  if ($ext !== 'html') gate_stop(401);
  // a page: the login screen in its place; once logged in, the same address opens the real page
  http_response_code(200);
  header('Cache-Control: no-store');
  header('Content-Type: text/html; charset=utf-8');
  $v = (string)@filemtime(__DIR__ . '/rg.js');
  echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>Shenandoah Cabin</title>
<link rel="apple-touch-icon" href="icons/apple-touch-icon.png"><link rel="icon" type="image/png" href="icons/icon-192.png"><link rel="manifest" href="manifest.webmanifest">
<meta name="apple-mobile-web-app-title" content="Shenandoah Cabin"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black">
<meta name="theme-color" content="#1b1d22">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap">
<style>*{box-sizing:border-box}html{background:#1b1d22;color-scheme:dark}body{margin:0;min-height:100vh;background:linear-gradient(170deg,#2c3037 0%,#22252b 55%,#1b1d22 100%) fixed;color:#eef1f6;font:15px/1.55 "Inter",system-ui,sans-serif}
.gate-msg{max-width:420px;margin:30vh auto 0;padding:0 24px;text-align:center;color:#9aa3b3}</style>
</head>
<body>
<script src="rg.js?v=$v"></script>
<script>
// Logged in: open the real page at this same address. If the server still answers with this screen, say so instead of reloading forever.
RG.login().then(()=>{let t=0;try{t=+sessionStorage.getItem("rg-gate")||0}catch(e){}
  if(Date.now()-t<15000){document.body.insertAdjacentHTML("beforeend",'<p class="gate-msg">You are logged in, but the page didn\'t open. Close the app and open it again.</p>');return}
  try{sessionStorage.setItem("rg-gate",String(Date.now()))}catch(e){}location.reload()});
</script>
</body>
</html>
HTML;
  exit;
}

// the file itself, with "not modified" answers so phones that already have it don't download it again
$mtime = (int)filemtime($path); $size = (int)filesize($path);
$etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';
$fresh = in_array($ext, ['html', 'js', 'css', 'webmanifest', 'json'], true);
header('Cache-Control: ' . ($open ? 'public' : 'private') . ', ' . ($fresh ? 'no-cache' : 'max-age=86400'));
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
header('Content-Type: ' . GATE_TYPES[$ext]);
header('Content-Length: ' . $size);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($path);
