<?php
/* Ridge Games: "play together" games where everyone is on their own phone (everything except poker, which is poker.php).
   A room's state (JSON) has the shared lobby part (invited, ready, start_at; see api.php) plus:
     players [{id,name}]  who joined
     race:     mind, level, name, seed, results {id: {win, score, left}}
     imposter: cat, word, imposter, first, phase (clue|vote|guess|over), votes {voter: target}, caught, guess, winner
   The server keeps the secrets (the imposter, the word); party_view() shows each phone only what it may see. Included by api.php. */

declare(strict_types=1);
if (!defined('RG_API')) { http_response_code(404); exit; }

const PARTY_GAMES = ['race', 'imposter'];

/* Imposter words, family friendly, grouped so the imposter gets a hint (the category). */
const IMP_WORDS = [
  'Camping' => ['Tent', 'Campfire', 'Marshmallow', 'Sleeping bag', 'Flashlight', 'Backpack', 'Hiking boots', 'Map', 'Compass', 'Cabin', 'Hammock', 'Bug spray'],
  'Animals' => ['Bear', 'Deer', 'Owl', 'Eagle', 'Snake', 'Raccoon', 'Squirrel', 'Wolf', 'Fox', 'Rabbit', 'Turtle', 'Skunk'],
  'Food' => ['Pizza', 'Pancakes', 'Burger', 'Sushi', 'Tacos', 'Ice cream', 'Popcorn', 'Spaghetti', 'Hot dog', 'Chocolate', 'Watermelon', 'Barbecue'],
  'Places' => ['Beach', 'Airport', 'Hospital', 'School', 'Library', 'Movie theater', 'Supermarket', 'Gym', 'Zoo', 'Museum', 'Restaurant', 'Train station'],
  'Jobs' => ['Doctor', 'Teacher', 'Pilot', 'Chef', 'Firefighter', 'Police officer', 'Farmer', 'Dentist', 'Astronaut', 'Plumber', 'Lifeguard', 'Park ranger'],
  'Sports' => ['Soccer', 'Basketball', 'Tennis', 'Swimming', 'Skiing', 'Golf', 'Bowling', 'Baseball', 'Boxing', 'Surfing', 'Volleyball', 'Fishing'],
  'At home' => ['Fridge', 'Sofa', 'Toothbrush', 'Television', 'Pillow', 'Microwave', 'Mirror', 'Washing machine', 'Lamp', 'Bathtub', 'Doorbell', 'Remote control'],
  'Nature' => ['Waterfall', 'Mountain', 'River', 'Forest', 'Thunderstorm', 'Rainbow', 'Cave', 'Sunset', 'Volcano', 'Desert', 'Lake', 'Snow'],
];

function party_new(string $game, array $b): array {
  $st = ['players' => [], 'final' => null];
  if ($game === 'race') {
    $mind = (string)($b['mind'] ?? '');
    if (!preg_match('/^[a-z]{3,20}$/', $mind)) throw new RuntimeException('Pick a mind game.');
    $st += ['mind' => $mind, 'name' => clean_text($b['name'] ?? 'Mind game', 40) ?: 'Mind game', 'level' => max(1, min(5, (int)($b['level'] ?? 1))), 'seed' => 0, 'results' => []];
  } elseif ($game === 'imposter') {
    $cat = (string)($b['cat'] ?? 'any');
    if ($cat !== 'any' && !isset(IMP_WORDS[$cat])) $cat = 'any';
    $st += ['pick' => $cat, 'cat' => '', 'word' => '', 'imposter' => '', 'first' => '', 'phase' => 'clue', 'votes' => [], 'caught' => null, 'guess' => null, 'winner' => null];
  }
  return $st;
}

function party_join(array &$st, array $p): void {
  foreach ($st['players'] as $x) if ($x['id'] === $p['id']) throw new RuntimeException("You're already in.");
  if (count($st['players']) >= 12) throw new RuntimeException('This game is full.');
  $st['players'][] = ['id' => $p['id'], 'name' => $p['name']];
}
function party_leave(array &$st, string $id): void {
  $st['players'] = array_values(array_filter($st['players'], fn($x) => $x['id'] !== $id));
}

/* Everyone is ready: set the game up. */
function party_begin(string $game, array &$st): void {
  $ids = array_column($st['players'], 'id');
  if ($game === 'race') { $st['seed'] = random_int(1, 2147483646); $st['results'] = []; }
  if ($game === 'imposter') {
    if (count($ids) < 3) throw new RuntimeException('Imposter needs at least 3 players.');
    $cats = array_keys(IMP_WORDS);
    $cat = $st['pick'] === 'any' ? $cats[random_int(0, count($cats) - 1)] : $st['pick'];
    $words = IMP_WORDS[$cat];
    $st['cat'] = $cat; $st['word'] = $words[random_int(0, count($words) - 1)];
    $st['imposter'] = $ids[random_int(0, count($ids) - 1)];
    $st['first'] = $ids[random_int(0, count($ids) - 1)];
    $st['phase'] = 'clue'; $st['votes'] = []; $st['caught'] = null; $st['guess'] = null; $st['winner'] = null;
  }
}

/* A move from one phone. Returns true when the game just ended. */
function party_move(string $game, array &$st, array $m, bool $host, array $b): bool {
  $me = $m['id']; $ids = array_column($st['players'], 'id');
  if (!in_array($me, $ids, true)) throw new RuntimeException("You're watching this game.");
  $do = (string)($b['do'] ?? '');
  if ($game === 'race') {
    if ($do !== 'finish') throw new RuntimeException('Unknown move.');
    if (isset($st['results'][$me])) return false; // already in (a double tap)
    $st['results'][$me] = ['win' => !empty($b['win']), 'score' => max(0, min(9999, (int)($b['score'] ?? 0))), 'left' => max(0, min(900, (int)($b['left'] ?? 0)))];
    if (count(array_intersect($ids, array_keys($st['results']))) === count($ids)) { party_rank($game, $st); return true; }
    return false;
  }
  if ($game === 'imposter') {
    if ($do === 'vote_now') {
      if (!$host) throw new RuntimeException('The host starts the vote.');
      if ($st['phase'] !== 'clue') throw new RuntimeException('Voting has already started.');
      $st['phase'] = 'vote'; return false;
    }
    if ($do === 'vote') {
      if ($st['phase'] !== 'vote') throw new RuntimeException('It is not time to vote.');
      $t = (string)($b['target'] ?? '');
      if (!in_array($t, $ids, true) || $t === $me) throw new RuntimeException('Pick someone else.');
      $st['votes'][$me] = $t;
      if (count(array_intersect($ids, array_keys($st['votes']))) === count($ids)) imp_count($st);
      return $st['phase'] === 'over';
    }
    if ($do === 'guess') {
      if ($st['phase'] !== 'guess' || $me !== $st['imposter']) throw new RuntimeException('Only the caught imposter guesses.');
      $g = clean_text($b['guess'] ?? '', 40);
      $st['guess'] = $g;
      $norm = fn($s) => preg_replace('/[^a-z0-9]/', '', strtolower($s));
      $st['winner'] = $g !== '' && $norm($g) === $norm($st['word']) ? 'imposter' : 'crew';
      $st['phase'] = 'over'; party_rank($game, $st); return true;
    }
    throw new RuntimeException('Unknown move.');
  }
  throw new RuntimeException('Unknown game.');
}

/* Votes are in: most votes is out; a tie means nobody is caught, and the imposter wins. */
function imp_count(array &$st): void {
  $n = array_count_values(array_values($st['votes'])); arsort($n);
  $top = array_keys($n, max($n), true);
  if (count($top) === 1 && $top[0] === $st['imposter']) { $st['caught'] = true; $st['phase'] = 'guess'; return; }
  $st['caught'] = false; $st['winner'] = 'imposter'; $st['phase'] = 'over'; party_rank('imposter', $st);
}

/* The final ranking: rows [{id, place, bonus, score, note}]. */
function party_rank(string $game, array &$st): void {
  $ids = array_column($st['players'], 'id'); $rows = [];
  if ($game === 'race') {
    // Finished the game: more seconds left = better. Didn't finish: higher score = better. Never sent a result: no place.
    $per = bonus_per();
    foreach ($ids as $id) {
      $r = $st['results'][$id] ?? null;
      if (!$r) { $rows[] = ['id' => $id, 'place' => null, 'bonus' => 0, 'score' => null, 'k' => -1]; continue; }
      $k = $r['win'] ? 100000 + $r['left'] : $r['score'];
      $rows[] = ['id' => $id, 'place' => 0, 'bonus' => $r['win'] && $per > 0 ? intdiv($r['left'], $per) : 0, 'score' => $r['win'] ? $r['left'] : 0, 'k' => $k, 'win' => $r['win'], 'left' => $r['left'], 'got' => $r['score']];
    }
    usort($rows, fn($a, $b) => $b['k'] <=> $a['k']);
    $place = 0; $prev = null;
    foreach ($rows as $i => &$r) { if ($r['place'] === null) continue; if ($prev === null || $r['k'] !== $prev) $place = $i + 1; $r['place'] = $place; $prev = $r['k']; unset($r['k']); }
    unset($r);
    foreach ($rows as &$r) unset($r['k']); unset($r);
  } elseif ($game === 'imposter') {
    foreach ($ids as $id) {
      $won = ($st['winner'] === 'imposter') === ($id === $st['imposter']);
      $rows[] = ['id' => $id, 'place' => $won ? 1 : 2, 'bonus' => 0, 'score' => null];
    }
    usort($rows, fn($a, $b) => $a['place'] <=> $b['place']);
  }
  $st['final'] = $rows;
}

/* What one phone may see. */
function party_view(string $game, array $st, ?string $me, bool $live): array {
  $ids = array_column($st['players'], 'id'); $in = in_array($me, $ids, true);
  $v = ['players' => $st['players'], 'final' => $st['final']];
  if ($game === 'race') {
    $v += ['mind' => $st['mind'], 'name' => $st['name'], 'level' => $st['level'], 'seed' => $live ? $st['seed'] : 0,
      'done_ids' => array_keys($st['results']), 'results' => $st['final'] ? $st['results'] : (isset($st['results'][$me]) ? [$me => $st['results'][$me]] : [])];
  }
  if ($game === 'imposter') {
    $over = $st['phase'] === 'over';
    $v += ['pick' => $st['pick'], 'phase' => $st['phase'], 'first' => $st['first'], 'voted' => array_keys($st['votes'])];
    if ($live && $in) {
      $v['role'] = $me === $st['imposter'] ? 'imposter' : 'crew';
      $v['cat'] = $st['cat'];
      if ($me !== $st['imposter']) $v['word'] = $st['word'];
      if (isset($st['votes'][$me])) $v['my_vote'] = $st['votes'][$me];
    }
    if ($st['phase'] === 'guess' || $over) { $v['caught_id'] = $st['caught'] ? $st['imposter'] : null; $v['tally'] = array_count_values(array_values($st['votes'])); }
    if ($over) $v += ['cat' => $st['cat'], 'word' => $st['word'], 'imposter' => $st['imposter'], 'votes' => $st['votes'], 'guess' => $st['guess'], 'winner' => $st['winner'], 'caught' => $st['caught']];
  }
  return $v;
}
