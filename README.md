# Ridge Games

Party games for the Shenandoah trip: the Road to the Crown map, 18 mind games, card games (Texas Hold'em and more), an admin page and a coins scoreboard.

- Static pages + `api.php` (PHP 8, SQLite). Hosted at raffaelco.com/ridge-games/ (Hostinger pulls this repo).
- Data (database, photos, sessions) lives outside the web folder in `domains/raffaelco.com/ridge-data/`, never in this repo.
- First visit to `admin.html`: the admin (Raffael) picks his password, then sets each player's password.
- Local test: `RG_DATA=/tmp/rgdata php -S 127.0.0.1:8099`
