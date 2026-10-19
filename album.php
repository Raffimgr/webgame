<?php
/* The group's own trip photos (family pictures included), kept in ridge-data/album, out of this public repo,
   and shown only to logged-in players. The page shrinks each photo to 1600px JPEG before upload, which also drops GPS data.
   GET album.php -> {items:[{id,by,place,cap,at}]}   GET ?id=X -> the photo
   POST ?place=&cap= with the raw image (X-RG header) adds one.   POST ?del=X removes one (whoever added it, or the admin). */

require __DIR__ . '/guide_lib.php';

const ALBUM_MAX = 6 * 1048576;
const ALBUM_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

function album_dir(): string { $d = data_dir() . '/album'; if (!is_dir($d)) mkdir($d, 0700, true); return $d; }
function album_list(): array { $f = album_dir() . '/album.json'; $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null; return is_array($j) ? $j : []; }
function album_save(array $items): void { if (file_put_contents(album_dir() . '/album.json', json_encode(array_values($items), JSON_UNESCAPED_SLASHES), LOCK_EX) === false) fail('Could not save.', 500); }
function clean_id($s): string { return preg_match('/^[a-f0-9]{16}$/', (string)$s) ? (string)$s : ''; }

try {
  $pid = guide_login();
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (isset($_GET['del'])) {
      $id = clean_id($_GET['del']); $items = album_list(); $keep = [];
      foreach ($items as $it) {
        if ($it['id'] === $id) {
          if ($it['by'] !== $pid && !guide_is_admin($pid)) fail('Only the person who added it (or the admin) can remove it.', 403);
          @unlink(album_dir() . '/' . $it['id'] . '.' . $it['ext']);
        } else $keep[] = $it;
      }
      album_save($keep); out(['ok' => 1]);
    }
    $raw = file_get_contents('php://input', false, null, 0, ALBUM_MAX + 1);
    if ($raw === false || $raw === '') fail('No picture came through. Try again.');
    if (strlen($raw) > ALBUM_MAX) fail('That picture is too big.');
    $info = @getimagesizefromstring($raw);
    $ext = $info ? (ALBUM_TYPES[$info['mime']] ?? null) : null;
    if (!$ext) fail('Please pick a JPG, PNG or WebP picture.');
    $id = bin2hex(random_bytes(8));
    $f = album_dir() . "/$id.$ext";
    if (file_put_contents($f, $raw, LOCK_EX) === false) fail('Could not save.', 500);
    @chmod($f, 0600);
    $item = ['id' => $id, 'ext' => $ext, 'by' => $pid, 'place' => preg_replace('/[^a-zA-Z0-9]/', '', (string)($_GET['place'] ?? '')),
      'cap' => mb_substr(trim((string)($_GET['cap'] ?? '')), 0, 120), 'w' => (int)$info[0], 'h' => (int)$info[1], 'at' => date('c')];
    $items = album_list(); $items[] = $item; album_save($items);
    unset($item['ext']); out($item);
  }
  if (isset($_GET['id'])) {
    $id = clean_id($_GET['id']);
    foreach (album_list() as $it) if ($it['id'] === $id) {
      $f = album_dir() . '/' . $it['id'] . '.' . $it['ext'];
      if (!is_file($f)) break;
      header('Content-Type: ' . array_search($it['ext'], ALBUM_TYPES, true));
      header('Cache-Control: private, max-age=604800');
      header('Content-Length: ' . filesize($f));
      readfile($f); exit;
    }
    fail('Photo not found.', 404);
  }
  $items = array_map(function ($it) { unset($it['ext']); return $it; }, album_list());
  out(['items' => array_reverse($items)]);
} catch (Throwable $e) {
  fail('Server problem. Try again.', 500);
}
