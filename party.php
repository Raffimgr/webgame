<?php
/* Ridge Games: "play together" games where everyone is on their own phone (everything except poker, which is poker.php).
   A room's state (JSON) has the shared lobby part (invited, ready, start_at; see api.php) plus:
     players [{id,name}]  who joined
     race:     mind, level, name, seed, results {id: {win, score, left}}
     imposter: cat, word, imposter, first, phase (clue|vote|guess|over), votes {voter: target}, caught, guess, winner
     trivia:   qs [[question, [4 answers], right]], i, phase (q|reveal), at (when this phase began), ans {id: [choice, seconds]}, pts {id}
     reaction: round, alive [ids], outs [[ids out in round 1], ...], go_at, taps {id: seconds | -1 early}, phase (wait|result), at
     likely:   prompts, i, phase (vote|reveal), votes {voter: target}, pts {id}
   The server keeps the secrets (the imposter, the word); party_view() shows each phone only what it may see. Included by api.php. */

declare(strict_types=1);
if (!defined('RG_API')) { http_response_code(404); exit; }

const PARTY_GAMES = ['race', 'imposter', 'trivia', 'reaction', 'likely'];
const TRIVIA_TIME = 20;   // seconds to answer; points = seconds left on a right answer
const TRIVIA_SHOW = 5;    // seconds the right answer stays up before the next question
const TRIVIA_COUNT = 10;
const LIKELY_COUNT = 8;

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


/* Trivia: the right answer is always first here; the order is shuffled when a game starts. */
const TRIVIA = [
  ['Which state is Shenandoah National Park in?', ['Virginia', 'West Virginia', 'North Carolina', 'Maryland']],
  ['How long is Skyline Drive?', ['105 miles', '55 miles', '205 miles', '75 miles']],
  ['What is the highest peak in Shenandoah National Park?', ['Hawksbill Mountain', 'Old Rag', 'Stony Man', 'Mount Marshall']],
  ['Which famous hiking trail runs through Shenandoah?', ['The Appalachian Trail', 'The Pacific Crest Trail', 'The John Muir Trail', 'The Oregon Trail']],
  ['Which mountain is famous in Shenandoah for its rock scramble?', ['Old Rag', 'Hawksbill', 'Bearfence', 'Signal Knob']],
  ['What is the speed limit on Skyline Drive?', ['35 mph', '45 mph', '25 mph', '55 mph']],
  ['Which big animal lives in Shenandoah in large numbers?', ['Black bear', 'Grizzly bear', 'Moose', 'Bison']],
  ['Which mountain range is Shenandoah part of?', ['Blue Ridge Mountains', 'Rocky Mountains', 'Sierra Nevada', 'Cascade Range']],
  ['Shenandoah National Park was created in which decade?', ['The 1930s', 'The 1890s', 'The 1960s', 'The 1910s']],
  ['Which famous caves are close to Shenandoah?', ['Luray Caverns', 'Carlsbad Caverns', 'Mammoth Cave', 'Ruby Falls']],
  ['What is the capital of Virginia?', ['Richmond', 'Norfolk', 'Virginia Beach', 'Charlottesville']],
  ["What is Virginia's nickname?", ['Old Dominion', 'Sunshine State', 'Lone Star State', 'Keystone State']],
  ["What is Virginia's state bird?", ['Northern Cardinal', 'Blue Jay', 'Robin', 'Bald Eagle']],
  ['Which president lived at Mount Vernon?', ['George Washington', 'Thomas Jefferson', 'Abraham Lincoln', 'John Adams']],
  ['Monticello was the home of which president?', ['Thomas Jefferson', 'George Washington', 'James Madison', 'Theodore Roosevelt']],
  ['In which year was Jamestown, Virginia founded?', ['1607', '1776', '1492', '1620']],
  ['Which is the largest planet in our solar system?', ['Jupiter', 'Saturn', 'Neptune', 'Earth']],
  ['What is the fastest land animal?', ['Cheetah', 'Lion', 'Horse', 'Greyhound']],
  ['Which is the largest ocean?', ['Pacific', 'Atlantic', 'Indian', 'Arctic']],
  ['How many continents are there?', ['7', '5', '6', '8']],
  ['What is the hardest natural substance?', ['Diamond', 'Gold', 'Iron', 'Quartz']],
  ['How many legs does a spider have?', ['8', '6', '10', '12']],
  ['Which planet is called the Red Planet?', ['Mars', 'Venus', 'Mercury', 'Jupiter']],
  ['What is the capital of Japan?', ['Tokyo', 'Kyoto', 'Osaka', 'Seoul']],
  ['What is the capital of Canada?', ['Ottawa', 'Toronto', 'Vancouver', 'Montreal']],
  ['What is the capital of Australia?', ['Canberra', 'Sydney', 'Melbourne', 'Perth']],
  ['What is the capital of Lebanon?', ['Beirut', 'Damascus', 'Amman', 'Tripoli']],
  ['What is the capital of Armenia?', ['Yerevan', 'Tbilisi', 'Baku', 'Gyumri']],
  ['Who painted the Mona Lisa?', ['Leonardo da Vinci', 'Pablo Picasso', 'Vincent van Gogh', 'Michelangelo']],
  ['What is the tallest mountain in the world?', ['Mount Everest', 'K2', 'Kilimanjaro', 'Mont Blanc']],
  ['How many sides does a hexagon have?', ['6', '5', '7', '8']],
  ['How many minutes are in a day?', ['1,440', '1,200', '2,400', '960']],
  ['How many hearts does an octopus have?', ['3', '1', '2', '8']],
  ['Which gas do plants take in from the air?', ['Carbon dioxide', 'Oxygen', 'Nitrogen', 'Helium']],
  ['What is the largest hot desert in the world?', ['Sahara', 'Gobi', 'Kalahari', 'Mojave']],
  ['How many players does a soccer team have on the field?', ['11', '9', '10', '12']],
  ['How many rings are on the Olympic flag?', ['5', '4', '6', '7']],
  ['How many keys does a standard piano have?', ['88', '76', '61', '100']],
  ['Who wrote the Harry Potter books?', ['J.K. Rowling', 'Roald Dahl', 'C.S. Lewis', 'J.R.R. Tolkien']],
  ["What is the name of Elsa's sister in Frozen?", ['Anna', 'Ariel', 'Belle', 'Moana']],
  ['In which year did people first walk on the Moon?', ['1969', '1959', '1975', '1981']],
  ['What is the chemical symbol for gold?', ['Au', 'Ag', 'Go', 'Gd']],
  ['How many bones does an adult human have?', ['206', '186', '256', '306']],
  ['How many days are in a leap year?', ['366', '365', '364', '367']],
  ['What is the largest animal on Earth?', ['Blue whale', 'African elephant', 'Giraffe', 'Great white shark']],
  ['Which fruit has its seeds on the outside?', ['Strawberry', 'Raspberry', 'Kiwi', 'Blueberry']],
  ['What do bees make?', ['Honey', 'Wax only', 'Silk', 'Maple syrup']],
  ['Which instrument usually has 6 strings?', ['Guitar', 'Violin', 'Harp', 'Cello']],
  ['At sea level, water boils at what temperature?', ['100 °C', '90 °C', '120 °C', '80 °C']],
  ['What is the closest star to Earth?', ['The Sun', 'Sirius', 'Polaris', 'Alpha Centauri']],
  ['Which animal is known as the King of the Jungle?', ['Lion', 'Tiger', 'Gorilla', 'Elephant']],
  ['What color do you get when you mix blue and yellow?', ['Green', 'Purple', 'Orange', 'Brown']],
  ['How many hours are in a week?', ['168', '144', '120', '196']],
  ['Which country gave the Statue of Liberty to the USA?', ['France', 'England', 'Spain', 'Italy']],
  ['What is the smallest prime number?', ['2', '1', '3', '0']],
  ['Which planet has the most famous rings?', ['Saturn', 'Mars', 'Venus', 'Mercury']],
  ['What is frozen water called?', ['Ice', 'Steam', 'Fog', 'Dew']],
  ['Which sport uses a shuttlecock?', ['Badminton', 'Tennis', 'Squash', 'Volleyball']],
  ['How many sides does a triangle have?', ['3', '4', '2', '5']],
  ['What is the main language spoken in Brazil?', ['Portuguese', 'Spanish', 'English', 'French']],
];

/* Most Likely To: everyone votes; you score when your pick matches the group's top pick. */
const LIKELY = [
  'Who is most likely to get lost on a hiking trail?', 'Who is most likely to fall asleep first tonight?', 'Who is most likely to spot a bear first?',
  'Who is most likely to forget their phone charger?', 'Who is most likely to cook the best breakfast?', 'Who is most likely to win a dance-off?',
  'Who is most likely to laugh at their own joke?', 'Who is most likely to take 100 photos of one view?', 'Who is most likely to become famous?',
  'Who is most likely to eat the last marshmallow?', 'Who is most likely to wake up first?', 'Who is most likely to get scared by a noise at night?',
  'Who is most likely to win at poker?', 'Who is most likely to sing in the car?', 'Who is most likely to bring way too much luggage?',
  'Who is most likely to make friends with a stranger?', 'Who is most likely to cry at a movie?', 'Who is most likely to plan the whole trip?',
  'Who is most likely to be late?', 'Who is most likely to try the spiciest food?', 'Who is most likely to climb to the top of the mountain first?',
  'Who is most likely to tell the best scary story?', 'Who is most likely to lose their sunglasses?', 'Who is most likely to win a staring contest?',
  'Who is most likely to start a water fight?', 'Who is most likely to know the answer to every trivia question?', 'Who is most likely to adopt a stray animal?',
  'Who is most likely to cheat at a board game?', 'Who is most likely to give the best advice?', 'Who is most likely to fall into the river?',
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
  } elseif ($game === 'trivia') {
    $st += ['qs' => [], 'i' => 0, 'phase' => 'q', 'at' => 0, 'ans' => [], 'pts' => []];
  } elseif ($game === 'reaction') {
    $st += ['round' => 0, 'alive' => [], 'outs' => [], 'go_at' => 0, 'taps' => [], 'phase' => 'wait', 'at' => 0];
  } elseif ($game === 'likely') {
    $st += ['prompts' => [], 'i' => 0, 'phase' => 'vote', 'votes' => [], 'pts' => []];
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
  $go = microtime(true) + COUNTDOWN; // the moment the shared countdown ends
  if ($game === 'trivia') {
    $pick = party_pick(TRIVIA, TRIVIA_COUNT);
    $st['qs'] = array_map(function ($q) { $o = [0, 1, 2, 3]; shuffle($o); return [$q[0], array_map(fn($k) => $q[1][$k], $o), array_search(0, $o, true)]; }, $pick);
    $st['i'] = 0; $st['phase'] = 'q'; $st['at'] = $go; $st['ans'] = []; $st['pts'] = array_fill_keys($ids, 0);
  }
  if ($game === 'reaction') {
    $st['round'] = 1; $st['alive'] = $ids; $st['outs'] = []; $st['taps'] = []; $st['phase'] = 'wait';
    $st['go_at'] = $go + random_int(1500, 4500) / 1000;
  }
  if ($game === 'likely') {
    $st['prompts'] = party_pick(LIKELY, LIKELY_COUNT); $st['i'] = 0; $st['phase'] = 'vote'; $st['votes'] = []; $st['pts'] = array_fill_keys($ids, 0);
  }
}
function party_pick(array $all, int $n): array { $k = array_keys($all); shuffle($k); return array_map(fn($i) => $all[$i], array_slice($k, 0, min($n, count($k)))); }

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
  $now = microtime(true);
  if ($game === 'trivia') {
    if ($do === 'answer') {
      if ($st['phase'] !== 'q' || (int)($b['i'] ?? -1) !== $st['i']) throw new RuntimeException('Too late for that question.');
      if ($now < $st['at']) throw new RuntimeException('Wait for the question.');
      if (isset($st['ans'][$me])) return false;
      $c = (int)($b['choice'] ?? -1); if ($c < 0 || $c > 3) throw new RuntimeException('Pick an answer.');
      $used = $now - $st['at'];
      if ($used > TRIVIA_TIME + 1) throw new RuntimeException('Time is up.');
      $left = $c === $st['qs'][$st['i']][2] ? max(1, (int)ceil(TRIVIA_TIME - $used)) : 0;
      $st['ans'][$me] = [$c, $left]; $st['pts'][$me] = ($st['pts'][$me] ?? 0) + $left;
      if (count(array_intersect($ids, array_keys($st['ans']))) === count($ids)) { $st['phase'] = 'reveal'; $st['at'] = $now; }
      return false;
    }
    if ($do === 'tick') { // any phone may nudge the game on once the time is up
      if ($st['phase'] === 'q' && $now >= $st['at'] + TRIVIA_TIME) { $st['phase'] = 'reveal'; $st['at'] = $now; return false; }
      if ($st['phase'] === 'reveal' && $now >= $st['at'] + TRIVIA_SHOW - .3) {
        if ($st['i'] + 1 >= count($st['qs'])) { party_rank($game, $st); return true; }
        $st['i']++; $st['phase'] = 'q'; $st['at'] = $now; $st['ans'] = [];
      }
      return false;
    }
    throw new RuntimeException('Unknown move.');
  }
  if ($game === 'reaction') {
    if ($do === 'tap') {
      if ($st['phase'] !== 'wait' || (int)($b['round'] ?? 0) !== $st['round']) return false;
      if (!in_array($me, $st['alive'], true)) throw new RuntimeException("You're out. Cheer the others on!");
      if (isset($st['taps'][$me])) return false;
      $at = (float)($b['at'] ?? $now); $at = min($at, $now); // the phone's own reading of when it was tapped, in server time
      $st['taps'][$me] = ($now < $st['go_at'] - .25 || $at < $st['go_at']) ? -1 : round(min($at, $now) - $st['go_at'], 3);
      if (count(array_intersect($st['alive'], array_keys($st['taps']))) === count($st['alive'])) return react_round($st, $now);
      return false;
    }
    if ($do === 'tick') {
      if ($st['phase'] === 'wait' && $now >= $st['go_at'] + 3) return react_round($st, $now);
      if ($st['phase'] === 'result' && $now >= $st['at'] + 3.5) { $st['round']++; $st['taps'] = []; $st['phase'] = 'wait'; $st['go_at'] = $now + random_int(2000, 5000) / 1000; }
      return false;
    }
    throw new RuntimeException('Unknown move.');
  }
  if ($game === 'likely') {
    if ($do === 'vote') {
      if ($st['phase'] !== 'vote') throw new RuntimeException('Voting is closed.');
      $t = (string)($b['target'] ?? ''); if (!in_array($t, $ids, true)) throw new RuntimeException('Pick a player.');
      $st['votes'][$me] = $t;
      if (count(array_intersect($ids, array_keys($st['votes']))) === count($ids)) {
        $n = array_count_values(array_values($st['votes'])); $top = array_keys($n, max($n), true);
        foreach ($st['votes'] as $v => $tg) if (in_array($tg, $top, true)) $st['pts'][$v] = ($st['pts'][$v] ?? 0) + 1;
        $st['phase'] = 'reveal';
      }
      return false;
    }
    if ($do === 'next') {
      if ($st['phase'] !== 'reveal') return false;
      if ($st['i'] + 1 >= count($st['prompts'])) { party_rank($game, $st); return true; }
      $st['i']++; $st['phase'] = 'vote'; $st['votes'] = [];
      return false;
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

/* A Last One Standing round is over: early tappers and no-shows are out; if nobody did that, the slowest is out.
   If everyone would be out, nobody is (the round is played again). Returns true when one player is left. */
function react_round(array &$st, float $now): bool {
  $bad = []; $times = [];
  foreach ($st['alive'] as $id) { $t = $st['taps'][$id] ?? null; if ($t === null || $t < 0) $bad[] = $id; else $times[$id] = $t; }
  if (!$bad && $times) { $max = max($times); $bad = array_keys(array_filter($times, fn($t) => $t === $max)); }
  if (count($bad) >= count($st['alive'])) $bad = []; // everybody slipped: replay the round
  if ($bad) { $st['outs'][] = $bad; $st['alive'] = array_values(array_diff($st['alive'], $bad)); }
  $st['last'] = ['round' => $st['round'], 'out' => $bad, 'taps' => $st['taps']];
  $st['phase'] = 'result'; $st['at'] = $now;
  if (count($st['alive']) <= 1) { party_rank('reaction', $st); return true; }
  return false;
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
  } elseif ($game === 'trivia' || $game === 'likely') {
    foreach ($ids as $id) $rows[] = ['id' => $id, 'place' => 0, 'bonus' => 0, 'score' => (int)($st['pts'][$id] ?? 0)];
    usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);
    foreach ($rows as $i => &$r) $r['place'] = $i && $rows[$i - 1]['score'] === $r['score'] ? $rows[$i - 1]['place'] : $i + 1;
    unset($r);
  } elseif ($game === 'reaction') {
    $place = 1;
    foreach ($st['alive'] as $id) $rows[] = ['id' => $id, 'place' => 1, 'bonus' => 0, 'score' => null];
    $place = count($rows) + 1;
    foreach (array_reverse($st['outs']) as $grp) { foreach ($grp as $id) $rows[] = ['id' => $id, 'place' => $place, 'bonus' => 0, 'score' => null]; $place += count($grp); }
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
  if ($game === 'trivia' && $live && $st['qs']) {
    $q = $st['qs'][$st['i']]; $rev = $st['phase'] === 'reveal' || $st['final'];
    $v += ['i' => $st['i'], 'n' => count($st['qs']), 'phase' => $st['phase'], 'at' => $st['at'], 'time' => TRIVIA_TIME, 'show' => TRIVIA_SHOW,
      'q' => $q[0], 'opts' => $q[1], 'answered' => array_keys($st['ans']), 'pts' => $st['pts'], 'mine' => isset($st['ans'][$me]) ? ($rev ? $st['ans'][$me] : [$st['ans'][$me][0], null]) : null];
    if ($rev) $v += ['right' => $q[2], 'ans' => $st['ans']];
  }
  if ($game === 'reaction' && $live) {
    $v += ['round' => $st['round'], 'alive' => $st['alive'], 'outs' => $st['outs'], 'go_at' => $st['go_at'], 'phase' => $st['phase'], 'at' => $st['at'],
      'tapped' => array_keys($st['taps']), 'last' => $st['last'] ?? null];
  }
  if ($game === 'likely' && $live && $st['prompts']) {
    $v += ['i' => $st['i'], 'n' => count($st['prompts']), 'phase' => $st['phase'], 'prompt' => $st['prompts'][$st['i']], 'voted' => array_keys($st['votes']), 'pts' => $st['pts'], 'my_vote' => $st['votes'][$me] ?? null];
    if ($st['phase'] === 'reveal') $v['tally'] = array_count_values(array_values($st['votes']));
  }
  return $v;
}
