# Digital Theater Posters

Full-screen, rotating movie posters for a portrait screen in your home theater, drawn from your *whole*
media library (Emby today, other sources via plugins). Home Assistant tells it to dim or black out
when a movie starts and to come back when it ends.

![Screenshot placeholder: the display on a portrait screen](docs/screenshot.jpg)

* **Lightweight:** a small Laravel app, plain JS/CSS display page, no build step, no queue worker, no Redis.
* **Offline-safe:** posters are cached on local disk. If the media server, network or database hiccups,
  the screen keeps showing what it has.
* **Home Assistant friendly:** a tiny token-protected JSON API (`play` / `pause` / `stop`, or explicit
  `normal` / `dim` / `black` with brightness and fade).
* **Unattended:** Windows scripts start Edge in kiosk mode at login and keep it running.

> License: MIT. This project will be open-sourced later.

---

## How it works

```
Emby ──(sync every 6 h)──► posters table + cached images on disk
                                         │
Edge kiosk ◄── GET / (display page) ─────┤
   │  every 30 s: GET /api/posters/next  ─┤   shuffle without repeats
   │  every 1 s:  GET /api/display/state ─┘   normal | dim | black
   ▼
Home Assistant ── POST /api/display/playback {"event":"play"} (Bearer token)
```

The display preloads each poster before crossfading to it, dims with a black overlay (so "black" is
truly black on any screen), pauses rotation while blacked out, and reloads itself once a day or when
you deploy new display files.

## Requirements

* Windows 10/11 PC connected to the poster screen (macOS works for development)
* [Laravel Herd](https://herd.laravel.com) with PHP 8.3+
* MySQL 8 (Herd Pro's MySQL service, or a standalone MySQL install)
* Composer (bundled with Herd)
* Microsoft Edge (preinstalled on Windows)
* Emby Server with an API key

## Setup (Windows + Herd + MySQL)

1. **Get the code** into your Herd sites folder (usually `%USERPROFILE%\Herd`):

   ```powershell
   cd $env:USERPROFILE\Herd
   git clone <repo-url> digital-theater-posters
   cd digital-theater-posters
   ```

   Herd serves it at `http://digital-theater-posters.test` automatically. If the code lives elsewhere,
   run `herd link digital-theater-posters` in its folder. `herd secure` (HTTPS) is optional.

2. **Create the database.** In Herd Pro: Services -> MySQL -> start it (and enable autostart), then
   create a database named `digital_theater_posters` (TablePlus, HeidiSQL, or):

   ```powershell
   mysql -u root -e "CREATE DATABASE digital_theater_posters CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   ```

3. **Install and configure:**

   ```powershell
   composer install --no-dev --optimize-autoloader
   copy .env.example .env
   php artisan key:generate
   ```

   Edit `.env`: set `APP_URL`, the `DB_*` values if yours differ, and `EMBY_URL` + `EMBY_API_KEY`
   (see below). Every option is documented in `.env.example`.

4. **Create the tables:**

   ```powershell
   php artisan migrate --force
   ```

5. **Create the API token** for Home Assistant:

   ```powershell
   php artisan posters:token --write   # or without --write to just print the POSTER_API_TOKEN= line
   ```

6. **Check the Emby connection and sync:**

   ```powershell
   php artisan posters:test-source
   php artisan posters:sync
   ```

   The first sync downloads every poster; later syncs only fetch what changed.

7. **Open the display** in a browser to check it: `http://digital-theater-posters.test`.

8. **Make it start by itself** (elevated PowerShell):

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\windows\install-autostart.ps1 -Url http://digital-theater-posters.test
   ```

   This registers the Edge kiosk + watchdog at logon and runs the Laravel scheduler every minute
   (scheduled sync + auto-restore). See [windows/README.md](windows/README.md) for options, finding Herd's
   PHP, auto-login, disabling sleep/screensaver and hiding notifications.

If you change `.env` later and use `php artisan config:cache`, run `php artisan config:clear` (or cache again).

## Emby

### API key

Emby web UI -> **Dashboard** (gear icon) -> **Advanced** -> **API Keys** -> **New API Key**, name it
"Digital Theater Posters", and put it in `EMBY_API_KEY`.

### Limiting what is shown

* `EMBY_USER_ID`: only items that user can see (handy to reuse an Emby user's parental controls).
  List users: `curl "http://EMBY:8096/emby/Users?api_key=KEY"` -> `Id`.
* `EMBY_LIBRARY_IDS`: comma separated library IDs. List libraries:

  ```bash
  curl "http://EMBY:8096/emby/Library/VirtualFolders?api_key=KEY"
  ```

  Use each library's `ItemId`. You can also open the library in the Emby web UI: the URL contains
  `parentId=<id>`.
* `POSTER_EXCLUDE_RATINGS`: e.g. `R,NC-17` to keep certain ratings off the screen.

## HTTP API

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/` | - | The display page |
| GET | `/api/display/state` | - | `{mode, brightness, fade_ms, version, updated_at, expires_at, reason, app_version}` |
| GET | `/api/posters/next?exclude[]=ID` | - | Next poster `{id, title, year, official_rating, runtime_minutes, image_url}` |
| GET | `/posters/{id}/image` | - | Cached poster image |
| GET | `/api/status` | - | Poster counts, last sync per source, display state |
| GET | `/up` | - | Health check |
| POST | `/api/display/state` | token | `{mode: normal\|dim\|black, brightness?: 0-100, fade_ms?: ms, reason?: text, expires_in_minutes?: n}` |
| POST | `/api/display/playback` | token | `{event: play\|pause\|resume\|stop}` mapped by `POSTER_ON_PLAY/PAUSE/STOP` |
| POST | `/api/sync` | token | Run a sync now |

**Auth:** send `Authorization: Bearer <POSTER_API_TOKEN>`. If headers are awkward in your tool, `?token=<token>`
in the URL also works (it may end up in logs, so prefer the header). Without a configured token the control
endpoints answer `503`; a wrong token gets `401`.

**Defaults:** `dim` without a brightness uses `POSTER_DIM_BRIGHTNESS`; `black` is always 0; `normal` is 100.
`fade_ms` defaults to `POSTER_FADE_MS`. Any dim/black state expires after `POSTER_AUTO_RESTORE_MINUTES`
(default 240, `0` = never) so a missed "stop" never leaves the screen dark forever; pass
`expires_in_minutes: 0` to keep a state until changed.

### curl

```bash
TOKEN=your-token
URL=http://digital-theater-posters.test

curl -X POST "$URL/api/display/playback" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"event":"play"}'
curl -X POST "$URL/api/display/state"    -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"mode":"dim","brightness":20,"fade_ms":3000}'
curl -X POST "$URL/api/display/state"    -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"mode":"normal"}'
curl -X POST "$URL/api/sync"             -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
curl "$URL/api/display/state"
curl "$URL/api/status"
```

PowerShell:

```powershell
Invoke-RestMethod -Method Post -Uri "$URL/api/display/playback" -Headers @{ Authorization = "Bearer $TOKEN" } -ContentType 'application/json' -Body '{"event":"pause"}'
```

## Home Assistant

`*.test` names only resolve on the theater PC itself. From Home Assistant use the PC's LAN IP. Herd picks the
site by host name, so either send a `Host` header (below), add a DNS/hosts entry for the name, or make this
the default site. Also allow inbound TCP 80/443 in Windows Firewall.

`secrets.yaml`:

```yaml
posters_auth: "Bearer your-token-here"
```

`configuration.yaml`:

```yaml
rest_command:
  posters_playback:
    url: "http://192.168.1.50/api/display/playback"
    method: post
    headers:
      Authorization: !secret posters_auth
      Host: digital-theater-posters.test
      Accept: application/json
    content_type: "application/json"
    payload: '{"event": "{{ event }}"}'

  posters_state:
    url: "http://192.168.1.50/api/display/state"
    method: post
    headers:
      Authorization: !secret posters_auth
      Host: digital-theater-posters.test
      Accept: application/json
    content_type: "application/json"
    payload: >
      {"mode": "{{ mode }}"
      {%- if brightness is defined %}, "brightness": {{ brightness }}{% endif -%}
      {%- if fade_ms is defined %}, "fade_ms": {{ fade_ms }}{% endif -%}}
```

Play / pause / stop scripts (or call them from dashboards):

```yaml
script:
  posters_play:
    sequence:
      - action: rest_command.posters_playback
        data: { event: play }
  posters_pause:
    sequence:
      - action: rest_command.posters_playback
        data: { event: pause }
  posters_stop:
    sequence:
      - action: rest_command.posters_playback
        data: { event: stop }
```

Automation following an Emby (or any) `media_player` entity:

```yaml
automation:
  - alias: "Posters follow theater playback"
    mode: queued
    triggers:
      - trigger: state
        entity_id: media_player.emby_theater   # your Emby client / player entity
    conditions:
      - "{{ trigger.from_state is not none and trigger.to_state.state != trigger.from_state.state }}"
    actions:
      - choose:
          - conditions: "{{ trigger.to_state.state == 'playing' }}"
            sequence:
              - action: rest_command.posters_playback
                data: { event: play }
          - conditions: "{{ trigger.to_state.state == 'paused' }}"
            sequence:
              - action: rest_command.posters_playback
                data: { event: pause }
          - conditions: "{{ trigger.to_state.state in ['idle', 'off', 'standby', 'unavailable', 'on'] }}"
            sequence:
              - action: rest_command.posters_playback
                data: { event: stop }
```

To react only to movies (not music), add a condition such as
`"{{ state_attr('media_player.emby_theater', 'media_content_type') == 'movie' }}"` to the play branch.

Lights-down example with an explicit dim:

```yaml
- action: rest_command.posters_state
  data: { mode: dim, brightness: 15, fade_ms: 4000 }
```

## Adding a source plugin

Sources are read-only plugins that never touch the database (the sync layer handles storage):

1. Write a class implementing `App\Posters\Contracts\PosterSource` (see `app/Posters/Sources/Emby` for a
   complete example): `testConnection()`, a lazy `items()` generator yielding `SourceItem`s, and
   `fetchImage()` returning a `FetchedImage`.
2. Add a block to `sources` in `config/posters.php` with `'driver' => YourSource::class` plus whatever
   settings it needs (read them from `.env`).
3. Add its key to `POSTER_SOURCES`, e.g. `POSTER_SOURCES=emby,jellyfin`.
4. `php artisan posters:test-source jellyfin`, then `php artisan posters:sync --source=jellyfin`.

Test it with `Http::fake()` like the Emby tests.

## Troubleshooting

| Symptom | Check |
|---|---|
| Black screen, no posters | Open `/api/status`: are there active posters? Run `php artisan posters:sync`. Is the state `black`? `curl /api/display/state`. |
| Screen stays black after a movie | HA didn't send `stop`. It returns to normal after `POSTER_AUTO_RESTORE_MINUTES` if the **Scheduler** task runs; or POST `{"mode":"normal"}`. |
| Scheduled sync / auto-restore never happen | Task Scheduler -> `\DigitalTheaterPosters\Scheduler` -> Last Run Result should be `0x0`. Run `php artisan schedule:list`. Wrong `-PhpPath`? Re-run the installer. |
| HA gets 503 | `POSTER_API_TOKEN` is empty (or config is cached: `php artisan config:clear`). |
| HA gets 401 | Header must be exactly `Authorization: Bearer <token>`. |
| HA can't connect | Use the PC's IP + `Host` header, allow port 80 in Windows Firewall, check Herd is running. |
| `posters:test-source` fails | `EMBY_URL` reachable from the PC? API key valid? Try `curl "$EMBY_URL/emby/System/Info?api_key=KEY"`. |
| Edge shows "restore pages" or sign-in prompts | It's using the wrong profile; start it via `start-display.ps1` (dedicated `--user-data-dir`). |
| Display didn't pick up changed settings | It reloads automatically when display files or `POSTER_*` display config change (within a few seconds of the next poster). Otherwise restart Edge: `.\windows\start-display.ps1 -StopKiosk`. |
| Logs | `storage\logs\laravel-*.log` and `%LOCALAPPDATA%\DigitalTheaterPosters\start-display.log`. |

## Development

```bash
composer install
cp .env.example .env   # DB_CONNECTION=sqlite is fine for development
php artisan key:generate && php artisan migrate
php artisan serve
php artisan test
```

Tests use in-memory SQLite and `Http::fake()`; no media server needed.
