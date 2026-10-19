<?php
/* Ridge Games: Texas Hold'em engine for online tables, a faithful PHP port of the poker part of cards.js
   (deck, evaluator + hand names with kickers, side pots, blinds incl. heads-up, min-raise / re-open rules, all-in run-out).
   The server deals and decides everything; phones only send actions. Included by api.php (and the tests). */

declare(strict_types=1);
if (!defined('RG_API')) { http_response_code(404); exit; }

const PK_SUITS = ['s', 'h', 'd', 'c'];
const PK_STREETS = ['Pre-flop', 'Flop', 'Turn', 'River', 'Showdown'];
const PK_MAIN = [8 => 1, 7 => 1, 6 => 2, 5 => 1, 4 => 1, 3 => 1, 2 => 2, 1 => 1, 0 => 1];

function pk_rank_ch(int $r): string { return [10 => '10', 11 => 'J', 12 => 'Q', 13 => 'K', 14 => 'A'][$r] ?? (string)$r; }
function pk_one(int $r): string { return [11 => 'Jack', 12 => 'Queen', 13 => 'King', 14 => 'Ace'][$r] ?? (string)$r; }
function pk_many(int $r): string { return [11 => 'Jacks', 12 => 'Queens', 13 => 'Kings', 14 => 'Aces'][$r] ?? $r . 's'; }

/* "As", "Td", "10h" -> ['r'=>14,'s'=>'s'] (tests use it) */
function pk_parse(string $t): array {
  $t = trim($t); $s = strtolower(substr($t, -1)); $x = strtoupper(substr($t, 0, -1));
  $r = ['A' => 14, 'K' => 13, 'Q' => 12, 'J' => 11, 'T' => 10][$x] ?? (int)$x;
  if ($r < 2 || $r > 14 || !in_array($s, PK_SUITS, true)) throw new RuntimeException("bad card $t");
  return ['r' => $r, 's' => $s];
}
function pk_cards(string $str): array { return array_map('pk_parse', preg_split('/\s+/', trim($str))); }
function pk_new_deck(): array { $d = []; foreach (PK_SUITS as $s) for ($r = 2; $r <= 14; $r++) $d[] = ['r' => $r, 's' => $s]; return $d; }
function pk_shuffle(array $a): array { for ($i = count($a) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$a[$i], $a[$j]] = [$a[$j], $a[$i]]; } return $a; }

/* ---------- evaluator: [category, tie-breakers...], compared left to right ---------- */
function pk_score5(array $cs): array {
  $rs = array_map(fn($c) => $c['r'], $cs); rsort($rs);
  $flush = count(array_unique(array_map(fn($c) => $c['s'], $cs))) === 1;
  $cnt = array_count_values($rs);
  $g = array_keys($cnt); usort($g, fn($a, $b) => ($cnt[$b] - $cnt[$a]) ?: ($b - $a));
  $c0 = $cnt[$g[0]]; $c1 = isset($g[1]) ? $cnt[$g[1]] : 0;
  $st = 0;
  if (count($g) === 5) { if ($rs[0] - $rs[4] === 4) $st = $rs[0]; elseif ($rs[0] === 14 && $rs[1] === 5) $st = 5; }
  if ($st && $flush) return [8, $st];
  if ($c0 === 4) return [7, $g[0], $g[1]];
  if ($c0 === 3 && $c1 === 2) return [6, $g[0], $g[1]];
  if ($flush) return array_merge([5], $rs);
  if ($st) return [4, $st];
  if ($c0 === 3) return array_merge([3], $g);
  if ($c0 === 2 && $c1 === 2) return array_merge([2], $g);
  if ($c0 === 2) return array_merge([1], $g);
  return array_merge([0], $rs);
}
function pk_cmp(array $a, array $b): int {
  for ($i = 0, $n = max(count($a), count($b)); $i < $n; $i++) { $d = ($a[$i] ?? 0) - ($b[$i] ?? 0); if ($d) return $d; }
  return 0;
}
/* best 5 of 5, 6 or 7 cards -> ['score','best','name'] */
function pk_best(array $cs): array {
  $n = count($cs);
  if ($n < 5) return ['score' => [-1], 'best' => $cs, 'name' => pk_pre($cs)];
  $top = null; $topC = null;
  for ($a = 0; $a < $n; $a++) for ($b = $a + 1; $b < $n; $b++) for ($c = $b + 1; $c < $n; $c++) for ($d = $c + 1; $d < $n; $d++) for ($e = $d + 1; $e < $n; $e++) {
    $h = [$cs[$a], $cs[$b], $cs[$c], $cs[$d], $cs[$e]]; $s = pk_score5($h);
    if ($top === null || pk_cmp($s, $top) > 0) { $top = $s; $topC = $h; }
  }
  return ['score' => $top, 'best' => $topC, 'name' => pk_hand_name($top)];
}
function pk_pre(array $cs): string {
  if (count($cs) !== 2) return '';
  [$x, $y] = $cs;
  if ($x['r'] === $y['r']) return 'Pocket ' . pk_many($x['r']);
  return pk_rank_ch(max($x['r'], $y['r'])) . '-' . pk_rank_ch(min($x['r'], $y['r'])) . ($x['s'] === $y['s'] ? ' suited' : '');
}
function pk_hand_name(array $s): string {
  switch ($s[0]) {
    case 8: return $s[1] === 14 ? 'Royal flush' : 'Straight flush, ' . pk_one($s[1]) . ' high';
    case 7: return 'Four of a kind, ' . pk_many($s[1]);
    case 6: return 'Full house, ' . pk_many($s[1]) . ' over ' . pk_many($s[2]);
    case 5: return 'Flush, ' . pk_one($s[1]) . ' high';
    case 4: return 'Straight, ' . pk_one($s[1]) . ' high';
    case 3: return 'Three of a kind, ' . pk_many($s[1]);
    case 2: return 'Two pair, ' . pk_many($s[1]) . ' and ' . pk_many($s[2]);
    case 1: return 'Pair of ' . pk_many($s[1]);
    case 0: return 'High card, ' . pk_one($s[1]);
  }
  return '';
}
/* Names for hands shown side by side: same main ranks -> add the kickers that decide it. */
function pk_kicker_names(array $scores): array {
  return array_map(function ($s) use ($scores) {
    $m = PK_MAIN[$s[0]] + 1; $name = pk_hand_name($s);
    if (count($s) <= $m) return $name;
    $key = implode(',', array_slice($s, 1, $m - 1));
    $sim = array_values(array_filter($scores, fn($t) => $t[0] === $s[0] && implode(',', array_slice($t, 1, $m - 1)) === $key));
    if (count($sim) < 2) return $name;
    $j = $m;
    while ($j < count($s) && array_reduce($sim, fn($ok, $t) => $ok && ($t[$j] ?? null) === $s[$j], true)) $j++;
    if ($j >= count($s)) return $name;
    $ks = array_slice($s, $m, $j - $m + 1);
    return $name . (count($ks) === 1 ? ', ' . pk_one($ks[0]) . ' kicker' : ', kickers ' . implode('-', array_map('pk_rank_ch', $ks)));
  }, $scores);
}

/* ---------- side pots: each level is set by the smallest stake among players still in ---------- */
function pk_build_pots(array $ps): array {
  $c = array_map(fn($p) => $p['total'], $ps); $pots = [];
  for (;;) {
    $live = []; foreach ($ps as $i => $p) if (!$p['folded'] && $c[$i] > 0) $live[] = $i;
    if (!$live) break;
    $m = min(array_map(fn($i) => $c[$i], $live)); $amt = 0; $from = 0;
    foreach ($c as $i => $v) { $t = min($v, $m); $amt += $t; $c[$i] -= $t; if ($t) $from++; }
    $pots[] = ['amount' => $amt, 'eligible' => $live, 'from' => $from];
  }
  $rest = array_sum($c);
  if ($rest && $pots) { $pots[count($pots) - 1]['amount'] += $rest; $pots[count($pots) - 1]['from']++; }
  return $pots;
}

/* ---------- one hand ----------
   pk_new(['players'=>[['id','name'],..], 'stacks'=>[..], 'sb', 'bb', 'dealer', 'deck'?]) -> table array.
   pk_options($g) -> what the player to act may do. pk_act($g, 'fold'|'check'|'call'|'raise'|'allin', $raiseTo). */
function pk_new(array $o): array {
  $n = count($o['players']); if ($n < 2) throw new RuntimeException('need 2+ players');
  $g = ['sb' => $o['sb'], 'bb' => $o['bb'], 'dealer' => (($o['dealer'] ?? 0) % $n + $n) % $n, 'deck' => $o['deck'] ?? pk_shuffle(pk_new_deck()),
    'board' => [], 'street' => 0, 'currentBet' => 0, 'minRaise' => $o['bb'], 'log' => [], 'done' => false, 'result' => null, 'foldSeq' => 0, 'cur' => -1, 'runout' => false, 'players' => []];
  foreach ($o['players'] as $i => $p) {
    $s = (int)($o['stacks'][$i] ?? $o['stack']);
    $g['players'][] = $p + ['stack' => $s, 'start' => $s, 'bet' => 0, 'total' => 0, 'folded' => false, 'allIn' => false, 'acted' => false, 'mayRaise' => true, 'hole' => [], 'foldAt' => null, 'foldStreet' => null, 'won' => 0];
  }
  for ($k = 0; $k < 2; $k++) for ($j = 1; $j <= $n; $j++) $g['players'][($g['dealer'] + $j) % $n]['hole'][] = array_shift($g['deck']);
  $g['sbI'] = $n === 2 ? $g['dealer'] : ($g['dealer'] + 1) % $n; $g['bbI'] = ($g['sbI'] + 1) % $n;
  pk_put($g, $g['sbI'], $g['sb']); pk_put($g, $g['bbI'], $g['bb']);
  $g['currentBet'] = $g['bb']; $g['minRaise'] = $g['bb'];
  $g['log'][] = $g['players'][$g['sbI']]['name'] . ' posts ' . $g['sb'] . ', ' . $g['players'][$g['bbI']]['name'] . ' posts ' . $g['bb'];
  $g['cur'] = pk_next_to_act($g, $g['bbI']);
  if ($g['cur'] < 0) pk_end_street($g); // the blinds put everyone (or all but one matched player) all-in: deal the board out
  return $g;
}
function pk_put(array &$g, int $i, int $amt): int {
  $p = &$g['players'][$i]; $a = max(0, min($amt, $p['stack']));
  $p['stack'] -= $a; $p['bet'] += $a; $p['total'] += $a; if ($p['stack'] === 0) $p['allIn'] = true;
  return $a;
}
function pk_needs(array $g, int $i): bool { $p = $g['players'][$i]; return !$p['folded'] && !$p['allIn'] && (!$p['acted'] || $p['bet'] < $g['currentBet']); }
function pk_next_to_act(array $g, int $from): int { $n = count($g['players']); for ($k = 1; $k <= $n; $k++) { $i = ($from + $k) % $n; if (pk_needs($g, $i)) return $i; } return -1; }
function pk_pot_total(array $g): int { return array_sum(array_map(fn($p) => $p['total'], $g['players'])); }
function pk_options(array $g): ?array {
  if ($g['done'] || $g['cur'] < 0) return null;
  $p = $g['players'][$g['cur']]; $toCall = max(0, $g['currentBet'] - $p['bet']); $maxTo = $p['bet'] + $p['stack']; $minTo = $g['currentBet'] + $g['minRaise'];
  $others = 0; foreach ($g['players'] as $j => $q) if ($j !== $g['cur'] && !$q['folded'] && !$q['allIn']) $others++;
  $canRaise = $p['mayRaise'] && $others > 0 && $p['stack'] > $toCall;
  return ['toCall' => $toCall, 'callAmt' => min($toCall, $p['stack']), 'callAllIn' => $toCall > 0 && $p['stack'] <= $toCall, 'canCheck' => $toCall === 0, 'canFold' => $toCall > 0,
    'canRaise' => $canRaise, 'raiseMin' => $canRaise ? min($minTo, $maxTo) : 0, 'raiseMax' => $canRaise ? $maxTo : 0, 'onlyAllIn' => $canRaise && $maxTo <= $minTo,
    'isBet' => $g['currentBet'] === 0, 'pot' => pk_pot_total($g), 'stack' => $p['stack'], 'bet' => $p['bet'], 'currentBet' => $g['currentBet'], 'bb' => $g['bb']];
}
function pk_act(array &$g, string $type, $amt = null): void {
  $o = pk_options($g); if (!$o) throw new RuntimeException('No action expected.');
  $i = $g['cur']; $msg = '';
  $call = function () use (&$g, $i, $o, &$msg) { $a = pk_put($g, $i, $o['callAmt']); $msg = $g['players'][$i]['allIn'] ? "calls $a (all-in)" : "calls $a"; };
  $raise = function (int $to) use (&$g, $i, &$msg, $call) {
    if ($to <= $g['currentBet']) { $call(); return; }
    $size = $to - $g['currentBet']; pk_put($g, $i, $to - $g['players'][$i]['bet']);
    // a full raise re-opens the betting for everyone; a short all-in does not (callers may only call or fold),
    // except an opening bet: players who only checked may still raise it
    if ($size >= $g['minRaise']) {
      $g['minRaise'] = $size;
      foreach ($g['players'] as $j => &$q) if ($j !== $i && !$q['folded'] && !$q['allIn']) { $q['acted'] = false; $q['mayRaise'] = true; }
      unset($q);
    } elseif ($g['currentBet'] === 0) {
      foreach ($g['players'] as $j => &$q) if ($j !== $i && !$q['folded'] && !$q['allIn']) $q['mayRaise'] = true;
      unset($q);
    }
    $msg = ($g['currentBet'] === 0 ? 'bets ' : 'raises to ') . $to . ($g['players'][$i]['allIn'] ? ' (all-in)' : ''); $g['currentBet'] = $to;
  };
  if ($type === 'fold') { $g['players'][$i]['folded'] = true; $g['players'][$i]['foldAt'] = ++$g['foldSeq']; $g['players'][$i]['foldStreet'] = $g['street']; $msg = 'folds'; }
  elseif ($type === 'check') { if (!$o['canCheck']) throw new RuntimeException("You can't check, there is a bet to call."); $msg = 'checks'; }
  elseif ($type === 'call') { if ($o['toCall'] <= 0) throw new RuntimeException('Nothing to call.'); $call(); }
  elseif ($type === 'raise' || $type === 'allin') {
    if (!$o['canRaise']) { if ($type === 'allin' && $o['callAllIn']) $call(); else throw new RuntimeException("You can't raise now."); }
    else {
      if ($type === 'allin') $to = $o['raiseMax'];
      elseif (is_int($amt)) $to = $amt;
      else throw new RuntimeException('No raise amount.');
      if ($to < $o['raiseMin'] && $to < $o['raiseMax']) throw new RuntimeException('That raise is too small.');
      $raise(min($to, $o['raiseMax']));
    }
  } else throw new RuntimeException('Unknown action.');
  $g['players'][$i]['acted'] = true; $g['players'][$i]['mayRaise'] = false;
  $g['log'][] = $g['players'][$i]['name'] . ' ' . $msg;
  $live = array_keys(array_filter($g['players'], fn($q) => !$q['folded']));
  if (count($live) === 1) { pk_finish_fold($g, $live[0]); return; }
  $nx = pk_next_to_act($g, $i);
  if ($nx >= 0) { $g['cur'] = $nx; return; }
  pk_end_street($g);
}
function pk_end_street(array &$g): void {
  for (;;) {
    foreach ($g['players'] as &$p) { $p['bet'] = 0; $p['acted'] = false; $p['mayRaise'] = true; } unset($p);
    $g['currentBet'] = 0; $g['minRaise'] = $g['bb'];
    if ($g['street'] === 3) { pk_showdown($g); return; }
    $g['street']++;
    $k = $g['street'] === 1 ? 3 : 1; for ($j = 0; $j < $k; $j++) $g['board'][] = array_shift($g['deck']);
    $g['log'][] = PK_STREETS[$g['street']] . ' dealt';
    $able = count(array_filter($g['players'], fn($q) => !$q['folded'] && !$q['allIn']));
    if ($able >= 2) { $g['cur'] = pk_next_to_act($g, $g['dealer']); return; }
    $g['runout'] = true;
  }
}
function pk_finish_fold(array &$g, int $w): void {
  $amt = pk_pot_total($g); $g['players'][$w]['stack'] += $amt; $g['players'][$w]['won'] = $amt; $g['done'] = true; $g['cur'] = -1;
  $g['result'] = ['type' => 'fold', 'pots' => [['amount' => $amt, 'eligible' => [$w], 'winners' => [$w], 'shares' => [$amt]]], 'main' => [$w]];
  $g['log'][] = $g['players'][$w]['name'] . " wins $amt (everyone else folded)";
}
function pk_showdown(array &$g): void {
  $g['street'] = 4; $g['done'] = true; $g['cur'] = -1; $n = count($g['players']);
  $live = [];
  foreach ($g['players'] as $i => &$p) if (!$p['folded']) { $p['hand'] = pk_best(array_merge($p['hole'], $g['board'])); $live[] = $i; } unset($p);
  $names = pk_kicker_names(array_map(fn($i) => $g['players'][$i]['hand']['score'], $live));
  foreach ($live as $k => $i) $g['players'][$i]['hand']['name'] = $names[$k];
  $pots = pk_build_pots($g['players']);
  foreach ($pots as &$pt) {
    $top = null; $w = [];
    foreach ($pt['eligible'] as $i) { $s = $g['players'][$i]['hand']['score']; $c = $top ? pk_cmp($s, $top) : 1; if ($c > 0) { $top = $s; $w = [$i]; } elseif ($c === 0) $w[] = $i; }
    // odd chips go to the winner(s) closest to the dealer's left
    usort($w, fn($a, $b) => (($a - $g['dealer'] - 1 + $n) % $n) - (($b - $g['dealer'] - 1 + $n) % $n));
    $share = intdiv($pt['amount'], count($w)); $odd = $pt['amount'] - $share * count($w);
    $pt['winners'] = $w; $pt['shares'] = [];
    foreach ($w as $k => $i) { $a = $share + ($odd > 0 ? 1 : 0); if ($odd > 0) $odd--; $pt['shares'][$k] = $a; $g['players'][$i]['stack'] += $a; $g['players'][$i]['won'] += $a; }
    $pt['returned'] = count($pt['eligible']) === 1 && $pt['from'] === 1; // only that player's own uncalled chips: handed back, not won
  }
  unset($pt);
  $g['result'] = ['type' => 'showdown', 'pots' => $pots, 'main' => $pots[0]['winners']];
  $g['log'][] = 'Showdown';
}

/* ================= online table (a room's state) =================
   state: chips, sb, bb, seats [{id,name}], stacks {id: chips}, dealer (seat index), hand_no, hand (table above, plus turn_at), final */
function room_new_state(int $chips): array { return ['chips' => $chips, 'sb' => intdiv($chips, 20), 'bb' => intdiv($chips, 10), 'seats' => [], 'stacks' => [], 'left' => [], 'dealer' => -1, 'hand_no' => 0, 'hand' => null, 'final' => null]; }

/* Deal the next hand: players with chips, in seat order; the button moves to the next seat that still has chips. */
function room_deal(array &$st, ?array $deck = null): void {
  $live = []; foreach ($st['seats'] as $k => $s) if (($st['stacks'][$s['id']] ?? 0) > 0) $live[] = $k;
  if (count($live) < 2) throw new RuntimeException('Only one player has chips left. End the game to see the ranking.');
  $n = count($st['seats']); $d = $st['dealer'];
  if ($d < 0) $d = $live[random_int(0, count($live) - 1)];
  else { for ($k = 1; $k <= $n; $k++) { $j = ($d + $k) % $n; if (in_array($j, $live, true)) { $d = $j; break; } } }
  $st['dealer'] = $d;
  $players = array_map(fn($k) => ['id' => $st['seats'][$k]['id'], 'name' => $st['seats'][$k]['name']], $live);
  $o = ['players' => $players, 'stacks' => array_map(fn($k) => $st['stacks'][$st['seats'][$k]['id']], $live), 'sb' => $st['sb'], 'bb' => $st['bb'], 'dealer' => array_search($d, $live, true)];
  if ($deck) $o['deck'] = $deck;
  $st['hand'] = pk_new($o) + ['dealer_id' => $st['seats'][$d]['id']];
  $st['hand_no']++;
  room_after($st);
}
/* Sit down / stand up. During a game: a newcomer is dealt in from the next hand with the starting chips
   (someone who stood up earlier gets their own chips back); standing up only between hands, or before you were dealt in. */
function room_sit(array &$st, array $p, bool $playing): void {
  foreach ($st['seats'] as $s) if ($s['id'] === $p['id']) throw new RuntimeException('You already have a seat.');
  if (count($st['seats']) >= 9) throw new RuntimeException('The table is full (9 seats).');
  $st['seats'][] = ['id' => $p['id'], 'name' => $p['name']];
  if ($playing) { $st['stacks'][$p['id']] = (int)($st['left'][$p['id']] ?? $st['chips']); unset($st['left'][$p['id']]); }
}
function room_stand(array &$st, string $id, bool $playing): void {
  $k = null; foreach ($st['seats'] as $i => $s) if ($s['id'] === $id) $k = $i;
  if ($k === null) throw new RuntimeException("You don't have a seat.");
  $h = $st['hand'];
  if ($playing && $h && !$h['done'] && in_array($id, array_column($h['players'], 'id'), true)) throw new RuntimeException('Wait until this hand is over, then stand up.');
  if ($playing) { $st['left'][$id] = (int)($st['stacks'][$id] ?? 0); unset($st['stacks'][$id]); }
  array_splice($st['seats'], $k, 1);
  if ($st['dealer'] >= 0 && $k <= $st['dealer']) { $n = count($st['seats']); $st['dealer'] = $n ? ($st['dealer'] - 1 + $n) % $n : -1; }
}
/* After every change: start the turn clock, and copy stacks back once the hand is over. */
function room_after(array &$st): void {
  $st['hand']['turn_at'] = time(); $st['seq'] = ($st['seq'] ?? 0) + 1;
  if ($st['hand']['done']) foreach ($st['hand']['players'] as $p) $st['stacks'][$p['id']] = $p['stack'];
}
/* End the game. A hand still running is called off: everyone gets back what they put in. Ranking: more chips = better, ties share. */
function room_finish(array &$st): void {
  $h = $st['hand'];
  if ($h && !$h['done']) foreach ($h['players'] as $p) $st['stacks'][$p['id']] = $p['stack'] + $p['total'];
  if (!$st['stacks'] && empty($st['left'])) { $st['final'] = []; return; }
  $rows = array_map(fn($s) => ['id' => $s['id'], 'chips' => (int)($st['stacks'][$s['id']] ?? 0)], $st['seats']);
  foreach ($st['left'] ?? [] as $id => $c) $rows[] = ['id' => (string)$id, 'chips' => (int)$c]; // stood up during the game: ranked with the chips they left with
  usort($rows, fn($a, $b) => $b['chips'] - $a['chips']);
  foreach ($rows as $k => &$r) $r['place'] = ($k && $rows[$k - 1]['chips'] === $r['chips']) ? $rows[$k - 1]['place'] : $k + 1;
  unset($r);
  $st['final'] = $rows;
}

/* What one viewer may see. Hole cards: only your own, plus at a showdown the hands that reached it. Never the deck. */
function room_view(array $room, ?string $me, int $now): array {
  $st = $room['state']; $h = $st['hand'];
  $seatIdx = -1; foreach ($st['seats'] as $k => $s) if ($s['id'] === $me) $seatIdx = $k;
  $inHand = [];
  if ($h) foreach ($h['players'] as $i => $p) $inHand[$p['id']] = $i;
  $seats = array_map(function ($s) use ($st, $h, $inHand) {
    $id = $s['id']; $stack = $h && isset($inHand[$id]) ? $h['players'][$inHand[$id]]['stack'] : (int)($st['stacks'][$id] ?? $st['chips']);
    $notDealt = $h !== null && !isset($inHand[$id]);
    return ['id' => $id, 'name' => $s['name'], 'stack' => $stack, 'out' => $notDealt && $stack <= 0, 'waiting' => $notDealt && $stack > 0];
  }, $st['seats']);
  $v = ['id' => (int)$room['id'], 'v' => (int)$room['version'], 'seq' => (int)($st['seq'] ?? 0), 'status' => $room['status'], 'host' => $room['host'],
    'chips' => $st['chips'], 'sb' => $st['sb'], 'bb' => $st['bb'], 'hand_no' => $st['hand_no'], 'seats' => $seats,
    'me' => ['id' => $me, 'seat' => $seatIdx, 'host' => $me !== null && $me === $room['host']], 'hand' => null, 'final' => $st['final']];
  if ($h) {
    $reveal = $h['done'] && $h['result']['type'] === 'showdown';
    $dealerId = $h['dealer_id'] ?? null;
    $ps = [];
    foreach ($h['players'] as $i => $p) {
      $show = ($p['id'] === $me) || ($reveal && !$p['folded']);
      $x = ['id' => $p['id'], 'name' => $p['name'], 'stack' => $p['stack'], 'start' => $p['start'], 'bet' => $p['bet'], 'total' => $p['total'],
        'folded' => $p['folded'], 'allIn' => $p['allIn'], 'won' => $p['won'], 'hole' => $show ? $p['hole'] : null];
      if ($reveal && !$p['folded']) $x['hand'] = ['name' => $p['hand']['name'], 'best' => $p['hand']['best']];
      $ps[] = $x;
    }
    $result = null;
    if ($h['done']) $result = ['type' => $h['result']['type'], 'main' => $h['result']['main'],
      'pots' => array_map(fn($pt) => ['amount' => $pt['amount'], 'winners' => $pt['winners'], 'shares' => $pt['shares'], 'returned' => !empty($pt['returned'])], $h['result']['pots'])];
    $mine = $h['cur'] >= 0 && $h['players'][$h['cur']]['id'] === $me;
    $v['hand'] = ['street' => $h['street'], 'board' => $h['board'], 'pot' => pk_pot_total($h), 'currentBet' => $h['currentBet'],
      'dealer' => $dealerId === null ? -1 : (int)($inHand[$dealerId] ?? -1), 'sbI' => $h['sbI'], 'bbI' => $h['bbI'], 'cur' => $h['cur'], 'runout' => $h['runout'],
      'done' => $h['done'], 'players' => $ps, 'log' => array_slice($h['log'], -8), 'result' => $result,
      'turn_age' => max(0, $now - (int)($h['turn_at'] ?? $now)), 'options' => $mine ? pk_options($h) : null];
  }
  return $v;
}
