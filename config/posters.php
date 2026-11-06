<?php

/*
|--------------------------------------------------------------------------
| Digital Theater Posters
|--------------------------------------------------------------------------
| Everything is driven from .env so the theater PC never needs code changes.
*/

return [

    /*
    | Which configured sources to sync from (comma separated keys from "sources").
    */
    'enabled_sources' => array_filter(array_map('trim', explode(',', env('POSTER_SOURCES', 'emby')))),

    /*
    | Source plugins. "driver" is a class implementing App\Posters\Contracts\PosterSource.
    | Everything else in the block is handed to that class's constructor.
    | To add a source later: write a class, add a block here, add its key to POSTER_SOURCES.
    */
    'sources' => [
        'emby' => [
            'driver' => App\Posters\Sources\Emby\EmbySource::class,
            'name' => env('EMBY_NAME', 'Emby'),
            'base_url' => env('EMBY_URL', 'http://127.0.0.1:8096'),
            'api_key' => env('EMBY_API_KEY'),
            // Optional: scope to a user's view (respects that user's parental controls).
            'user_id' => env('EMBY_USER_ID'),
            // Optional: comma separated library (parent) IDs. Empty = all libraries.
            'library_ids' => array_filter(array_map('trim', explode(',', env('EMBY_LIBRARY_IDS', '')))),
            // Emby item types to include. v1 is movies only.
            'item_types' => array_filter(array_map('trim', explode(',', env('EMBY_ITEM_TYPES', 'Movie')))),
            'page_size' => (int) env('EMBY_PAGE_SIZE', 200),
            'timeout' => (int) env('EMBY_TIMEOUT', 20),
        ],
    ],

    /*
    | Sync + local image cache. Images live on local disk so the display keeps
    | working when the media server or network is down.
    */
    'sync' => [
        // Cron expression for the scheduled sync (Laravel scheduler).
        'schedule' => env('POSTER_SYNC_CRON', '15 */6 * * *'),
        // Items missing from a source for this many consecutive full syncs are deactivated.
        'deactivate_after_missed_syncs' => (int) env('POSTER_DEACTIVATE_AFTER', 2),
    ],

    'images' => [
        'disk' => env('POSTER_IMAGE_DISK', 'posters'),
        'max_height' => (int) env('POSTER_IMAGE_MAX_HEIGHT', 2160),
        'quality' => (int) env('POSTER_IMAGE_QUALITY', 90),
    ],

    /*
    | Content filtering applied when choosing what to show.
    */
    'filters' => [
        // Comma separated official ratings to never show, e.g. "R,NC-17,NR".
        'exclude_ratings' => array_filter(array_map('trim', explode(',', env('POSTER_EXCLUDE_RATINGS', '')))),
    ],

    /*
    | The full-screen display.
    */
    'display' => [
        'rotate_seconds' => (int) env('POSTER_ROTATE_SECONDS', 30),
        'transition' => env('POSTER_TRANSITION', 'crossfade'), // crossfade | fade | cut
        'transition_ms' => (int) env('POSTER_TRANSITION_MS', 1500),
        'state_poll_ms' => (int) env('POSTER_STATE_POLL_MS', 1000),
        'fit' => env('POSTER_FIT', 'contain'), // contain | cover
        'background' => env('POSTER_BACKGROUND', '#000000'),
        'show_title' => (bool) env('POSTER_SHOW_TITLE', false),
    ],

    /*
    | Playback dimming. Home Assistant (or anything) calls the API; these are defaults.
    | brightness is 0-100 where 0 is fully black.
    */
    'dimming' => [
        'dim_brightness' => (int) env('POSTER_DIM_BRIGHTNESS', 10),
        'fade_ms' => (int) env('POSTER_FADE_MS', 2000),
        // What the "playback" convenience endpoint does for each event: normal | dim | black
        'on_play' => env('POSTER_ON_PLAY', 'black'),
        'on_pause' => env('POSTER_ON_PAUSE', 'dim'),
        'on_stop' => env('POSTER_ON_STOP', 'normal'),
        // Safety net: if nothing resets the state, return to normal after N minutes (0 = never).
        'auto_restore_minutes' => (int) env('POSTER_AUTO_RESTORE_MINUTES', 240),
    ],

    /*
    | Bearer token required for control endpoints (state changes, sync).
    | Generate with: php artisan posters:token
    */
    'api_token' => env('POSTER_API_TOKEN'),

];
