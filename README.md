# Ridge Games

Party games for the Shenandoah trip: the Road to the Crown map, 18 mind games, card games (Texas Hold'em and more), an admin page and a coins scoreboard.

- Poker on phones (`online.html`): online Texas Hold'em tables, one phone per player plus spectators. `poker.php` is the server-side engine (a port of the poker part of `cards.js`); the room endpoints are `room_*` in `api.php`.
- Play together (`play.html`, `lobby.js`, `party.php`): the host picks a game and the players; invites show on their home page; everyone taps Ready and a shared countdown starts the game on every phone. Games: Poker, Imposter, Mind races (same puzzles for everyone; seconds left give bonus coins, setting `bonus_per`, default 1 coin per 10 seconds). Finished games save to the scoreboard by themselves.
- Front page (`index.html`) is the Shenandoah Guide; its "Games for the trip" button opens the games home (`games.html`). `guide.html` just forwards to the front page for old links. Everyone logged in can like and comment on any place, place to eat, history event, video or photo (`talk.php`, stored in `ridge-data/talk.sqlite`).
- Static pages + `api.php` (PHP 8, SQLite). Hosted at raffaelco.com/ridge-games/ (Hostinger pulls this repo).
- Data (database, photos, sessions) lives outside the web folder in `domains/raffaelco.com/ridge-data/`, never in this repo.
- First visit to `admin.html`: the admin (Raffael) picks his password, then sets each player's password.
- Local test: `RG_DATA=/tmp/rgdata php -S 127.0.0.1:8099`
