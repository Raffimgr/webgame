<?php
/* The trip cabin's address, for the guide's map pin and "Directions to the cabin" button.
   Kept on the server (ridge-data/cabin.json), never in this public repo, and only given to logged-in players.
   GET cabin.php -> {address, ll}. POST {address, ll?} with the X-RG header saves it (any logged-in player). */

require __DIR__ . '/guide_lib.php';

try {
  $pid = guide_login();
  $file = data_dir() . '/cabin.json';
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $c = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    out(['address' => $c['address'] ?? null, 'll' => $c['ll'] ?? null]);
  }
  $b = json_decode(file_get_contents('php://input') ?: '', true);
  if (!is_array($b)) fail('Bad request.');
  $addr = mb_substr(trim((string)($b['address'] ?? '')), 0, 200);
  $ll = null;
  if (isset($b['ll']) && is_array($b['ll']) && count($b['ll']) === 2) {
    [$lat, $lng] = array_map('floatval', array_values($b['ll']));
    if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && ($lat || $lng)) $ll = [round($lat, 6), round($lng, 6)];
  }
  if ($addr === '') { @unlink($file); out(['address' => null, 'll' => null]); }
  if (file_put_contents($file, json_encode(['address' => $addr, 'll' => $ll, 'by' => $pid, 'at' => date('c')]), LOCK_EX) === false) fail('Could not save.', 500);
  @chmod($file, 0600);
  out(['address' => $addr, 'll' => $ll]);
} catch (Throwable $e) {
  fail('Server problem. Try again.', 500);
}
