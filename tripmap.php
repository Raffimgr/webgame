<?php
/* The illustrated park map a player uploaded for the guide. It is a commercial print, so it lives in
   ridge-data (outside the web folder and out of this public repo) and is only shown to logged-in players.
   GET tripmap.php -> the image (404 if none). GET ?info=1 -> {has, v}. POST the raw image with the X-RG header to replace it. */

require __DIR__ . '/guide_lib.php';

const MAP_MAX = 15 * 1048576;
const MAP_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

function map_file(): ?string {
  foreach (MAP_TYPES as $ext) { $f = data_dir() . "/tripmap.$ext"; if (is_file($f)) return $f; }
  return null;
}

try {
  guide_login();
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw = file_get_contents('php://input', false, null, 0, MAP_MAX + 1);
    if ($raw === false || $raw === '') fail('No picture came through. Try again.');
    if (strlen($raw) > MAP_MAX) fail('That picture is too big (15 MB max).');
    $info = @getimagesizefromstring($raw);
    $ext = $info ? (MAP_TYPES[$info['mime']] ?? null) : null;
    if (!$ext) fail('Please pick a JPG, PNG or WebP picture.');
    foreach (MAP_TYPES as $e) @unlink(data_dir() . "/tripmap.$e");
    $f = data_dir() . "/tripmap.$ext";
    if (file_put_contents($f, $raw, LOCK_EX) === false) fail('Could not save.', 500);
    @chmod($f, 0600);
    out(['has' => true, 'v' => filemtime($f)]);
  }
  $f = map_file();
  if (isset($_GET['info'])) out(['has' => (bool)$f, 'v' => $f ? filemtime($f) : null]);
  if (!$f) fail('No map uploaded yet.', 404);
  $mime = array_search(pathinfo($f, PATHINFO_EXTENSION), MAP_TYPES, true);
  header('Content-Type: ' . $mime);
  header('Cache-Control: private, max-age=86400');
  header('Content-Length: ' . filesize($f));
  readfile($f);
} catch (Throwable $e) {
  fail('Server problem. Try again.', 500);
}
