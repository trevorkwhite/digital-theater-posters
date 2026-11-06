# Digital Theater Posters

A lightweight Laravel 13 app that shows full-screen movie posters from the *whole* media library (Emby first), rotating. Home Assistant calls an API to dim or black out the screen when a movie starts.

Target runtime: **Windows home-theater PC, Laravel Herd, MySQL** (Herd Pro MySQL or standalone MySQL 8). Display runs in **Microsoft Edge kiosk mode** (no browser chrome) launched at login. Dev may also happen on macOS.

## Hard rules
- **No frontend build step.** No Vite, no npm. The display is a Blade view plus plain JS/CSS in `public/display/`. It must run on an offline LAN.
- **PHP 8.3+, Laravel 13 conventions.** Use the `bootstrap/app.php` config style (no Kernel classes). Routes in `routes/web.php` and `routes/api.php`.
- **Sources never touch the DB.** A `PosterSource` (see `app/Posters/Contracts/PosterSource.php`) only reads from its media server. Persistence and image storage are owned by `PosterSyncService`.
- **Everything is configurable via `.env`** through `config/posters.php`. Don't hard-code hosts, keys, timings.
- **The display must never go blank because of a backend problem.** Cached images on local disk; the JS keeps showing what it has if the API errors.
- Write Pest/PHPUnit feature tests with `Http::fake()` for external calls. Tests use SQLite in-memory (phpunit.xml); keep migrations portable between SQLite and MySQL.
- This environment cannot run `composer install` (Packagist is unreachable). Lint every PHP file with `php -l`. Tests will be run on the real machine.

## Layout and ownership

| Area | Files | Owner |
|---|---|---|
| Contracts (shared, do not change without coordinating) | `app/Posters/Contracts/*`, `app/Posters/Data/*`, `app/Posters/PosterSourceManager.php`, `config/posters.php` | lead |
| Emby source plugin | `app/Posters/Sources/Emby/*`, `tests/Feature/Sources/Emby*` | source agent |
| Cache + sync + poster selection | migrations for `posters` and `sync_runs`, `app/Models/Poster.php`, `app/Models/SyncRun.php`, `app/Posters/PosterSyncService.php`, `app/Posters/PosterPicker.php`, `app/Console/Commands/SyncPosters.php`, `config/filesystems.php` (`posters` disk), poster API controller, tests | cache agent |
| Foundation: display, dim API, auth, kiosk, ops | `routes/*`, `bootstrap/app.php`, middleware, display-state migration/model, `DisplayStateController`, `resources/views/display.blade.php`, `public/display/*`, `windows/*` scripts, `.env.example`, `README.md`, `docs/*` | foundation agent |

## Data model
- `posters`: id, source, source_item_id (unique together), media_type, title, sort_title, year, overview, official_rating, community_rating, runtime_minutes, genres (json), library_name, image_tag, image_path (relative path on the `posters` disk), image_mime, image_width, image_height, is_active, missed_syncs, last_seen_at, last_shown_at, show_count, extra (json), timestamps.
- `sync_runs`: id, source, status (running|succeeded|failed), items_seen, items_added, items_updated, images_downloaded, items_deactivated, error, started_at, finished_at.
- `display_states`: single row. mode (normal|dim|black), brightness (0-100), fade_ms, reason, expires_at, version (int, bumped on every change), timestamps.

## HTTP API (JSON)
Public (LAN, read-only, used by the display):
- `GET  /` → the full-screen display page.
- `GET  /api/display/state` → `{mode, brightness, fade_ms, version, updated_at}`
- `GET  /api/posters/next?exclude[]=ID` → `{id, title, year, official_rating, runtime_minutes, image_url}` (shuffle without repeats until all shown; respects filters). 404 JSON when library is empty.
- `GET  /posters/{poster}/image` → the cached file (long cache headers, ETag from image_tag).
- `GET  /api/status` → counts, last sync per source, display state.
- `GET  /up` → Laravel health.

Control (`Authorization: Bearer <POSTER_API_TOKEN>`; 401 otherwise; if no token configured, control endpoints are disabled with 503):
- `POST /api/display/state` body `{mode: normal|dim|black, brightness?: 0-100, fade_ms?: int, reason?: string, expires_in_minutes?: int}`
- `POST /api/display/playback` body `{event: play|pause|resume|stop}` → maps via `posters.dimming.on_*` (resume behaves like play).
- `POST /api/sync` → runs/queues a sync, returns the sync run(s).

## Artisan
- `posters:sync {--source=} {--force-images}`: sync enabled sources.
- `posters:test-source {source?}`: test connection.
- `posters:token`: generate a token and print the `.env` line.
- Scheduler runs `posters:sync` on `posters.sync.schedule` and `display:auto-restore` every minute.
