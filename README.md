# Ridge Games

Party games for the Shenandoah trip: the Road to the Crown map, 18 mind games, card games (Texas Hold'em and more), an admin page and a coins scoreboard.

- Poker on phones (`online.html`): online Texas Hold'em tables, one phone per player plus spectators. `poker.php` is the server-side engine (a port of the poker part of `cards.js`); the room endpoints are `room_*` in `api.php`.
- Play together (`play.html`, `lobby.js`, `party.php`): the host picks a game and the players; invites show on their home page; everyone taps Ready and a shared countdown starts the game on every phone. Games: Poker, Imposter, Mind races (same puzzles for everyone; seconds left give bonus coins, setting `bonus_per`, default 1 coin per 10 seconds). Finished games save to the scoreboard by themselves.
- Front page (`index.html`) is the Shenandoah Guide: Places (one "Closest first" menu to narrow them down), Map, History and Eat. The bottom bar opens the games home (`games.html`). `guide.html` just forwards to the front page for old links. Everyone logged in can like and comment on any place, place to eat or history event (`talk.js` + `talk.php`, stored in `ridge-data/talk.sqlite`).
- Trip Feed (`social.html`, `social.php`): everyone shares photos and videos, likes, comments and downloads them. Photos are kept in `ridge-data/social`; videos go to Raffi's Cloudflare R2 bucket `ridge-media` through the Worker in `cloudflare/media-worker.js` (served at ridge-media.raffaelco.workers.dev), which checks each upload ticket and viewing token with `social.php`.
- To-do (`plan.html`, `plan.php`): lists of what to buy, book and do for a trip or event, who is on it, due dates and notes (`ridge-data/plan.sqlite`). Anyone logged in can add, edit, take or tick off items; whoever added one (or the admin) can delete it.
- Group chat (`chat.html`, `chat.php`): one conversation for everyone, with hearts (double-tap), "seen by" and an unread count on the Chat button (`ridge-data/chat.sqlite`).
- Profiles (`profile.html`, `profile.html?id=…`): picture, name, color and password for your own; anyone's feed posts as a grid, their to-dos and games.
- Bottom bar (in `rg.js`): Trip / Games / Feed / Chat / To-do / Me on those main pages only, never inside a game. Pages load `rg.js?v=N`; bump N when rg.js changes, and `.htaccess` makes phones check for new copies of pages and scripts.
- Static pages + `api.php` (PHP 8, SQLite). Hosted at raffaelco.com/ridge-games/ (Hostinger pulls this repo).
- Data (database, photos, sessions) lives outside the web folder in `domains/raffaelco.com/ridge-data/`, never in this repo.
- First visit to `admin.html`: the admin (Raffael) picks his password, then sets each player's password.
- Local test: `RG_DATA=/tmp/rgdata php -S 127.0.0.1:8099`
