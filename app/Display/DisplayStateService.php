<?php

namespace App\Display;

use App\Models\DisplayState;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * All changes to the screen state go through here so that defaults, expiry
 * and the version counter are applied consistently.
 */
class DisplayStateService
{
    public const MAX_FADE_MS = 600000;

    public const PLAYBACK_EVENTS = ['play', 'pause', 'resume', 'stop'];

    private ?string $appVersion = null;

    /**
     * The current state. An expired non-normal state is restored to normal
     * (persisted, version bumped) so every reader sees the same thing.
     */
    public function current(): DisplayState
    {
        $state = DisplayState::current();

        if ($state->isExpired()) {
            return $this->restore($state, 'auto-restore: expired');
        }

        return $state;
    }

    /**
     * Change the screen state.
     *
     * @param  int|null  $brightness  null = default for the mode (normal 100, dim posters.dimming.dim_brightness, black 0)
     * @param  int|null  $fadeMs  null = posters.dimming.fade_ms
     * @param  int|null  $expiresInMinutes  null = posters.dimming.auto_restore_minutes; 0 = never. Ignored for normal.
     */
    public function set(
        string $mode,
        ?int $brightness = null,
        ?int $fadeMs = null,
        ?string $reason = null,
        ?int $expiresInMinutes = null,
    ): DisplayState {
        if (! in_array($mode, DisplayState::MODES, true)) {
            throw new InvalidArgumentException("Invalid display mode [{$mode}]. Expected one of: ".implode(', ', DisplayState::MODES).'.');
        }

        if ($brightness !== null && ($brightness < 0 || $brightness > 100)) {
            throw new InvalidArgumentException('Brightness must be between 0 and 100.');
        }

        if ($fadeMs !== null && ($fadeMs < 0 || $fadeMs > self::MAX_FADE_MS)) {
            throw new InvalidArgumentException('fade_ms must be between 0 and '.self::MAX_FADE_MS.'.');
        }

        if ($expiresInMinutes !== null && $expiresInMinutes < 0) {
            throw new InvalidArgumentException('expires_in_minutes must be 0 or more.');
        }

        $brightness = match ($mode) {
            DisplayState::MODE_BLACK => 0,
            DisplayState::MODE_DIM => $brightness ?? $this->clampBrightness((int) config('posters.dimming.dim_brightness', 10)),
            default => $brightness ?? 100,
        };

        $fadeMs ??= max(0, (int) config('posters.dimming.fade_ms', 2000));

        $expiresAt = null;
        if ($mode !== DisplayState::MODE_NORMAL) {
            $minutes = $expiresInMinutes ?? max(0, (int) config('posters.dimming.auto_restore_minutes', 0));
            $expiresAt = $minutes > 0 ? now()->addMinutes($minutes) : null;
        }

        return $this->write([
            'mode' => $mode,
            'brightness' => $brightness,
            'fade_ms' => $fadeMs,
            'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            'expires_at' => $expiresAt,
        ]);
    }

    /** Map a playback event (play|pause|resume|stop) to a mode via posters.dimming.on_*. */
    public function handlePlayback(string $event, ?string $reason = null): DisplayState
    {
        $event = strtolower(trim($event));

        $key = match ($event) {
            'play', 'resume' => 'on_play',
            'pause' => 'on_pause',
            'stop' => 'on_stop',
            default => throw new InvalidArgumentException("Invalid playback event [{$event}]. Expected one of: ".implode(', ', self::PLAYBACK_EVENTS).'.'),
        };

        $default = ['on_play' => 'black', 'on_pause' => 'dim', 'on_stop' => 'normal'][$key];
        $mode = strtolower((string) config("posters.dimming.{$key}", $default));

        if (! in_array($mode, DisplayState::MODES, true)) {
            $mode = $default;
        }

        return $this->set($mode, reason: $reason ?? "playback:{$event}");
    }

    /**
     * Reset an expired state back to normal. Returns true when something changed.
     */
    public function restoreIfExpired(): bool
    {
        $state = DisplayState::current();

        if (! $state->isExpired()) {
            return false;
        }

        $this->restore($state, 'auto-restore: expired');

        return true;
    }

    /** JSON shape returned by GET /api/display/state. */
    public function payload(?DisplayState $state = null): array
    {
        $state ??= $this->current();

        return [
            'mode' => $state->mode,
            'brightness' => (int) $state->brightness,
            'fade_ms' => (int) $state->fade_ms,
            'version' => (int) $state->version,
            'reason' => $state->reason,
            'expires_at' => $state->expires_at?->toIso8601String(),
            'updated_at' => $state->updated_at?->toIso8601String(),
            'app_version' => $this->appVersion(),
        ];
    }

    /**
     * Identifies the deployed display build. The display page reloads itself
     * when this changes. Uses config('app.version') when set, otherwise a hash
     * of the display asset mtimes plus the display config.
     */
    public function appVersion(): string
    {
        if ($this->appVersion !== null) {
            return $this->appVersion;
        }

        $configured = config('app.version');
        if (is_string($configured) && $configured !== '') {
            return $this->appVersion = $configured;
        }

        $parts = [];
        foreach ([
            public_path('display/display.js'),
            public_path('display/display.css'),
            resource_path('views/display.blade.php'),
        ] as $file) {
            $parts[] = is_file($file) ? (string) filemtime($file) : '0';
        }
        $parts[] = json_encode(config('posters.display'));

        return $this->appVersion = substr(sha1(implode('|', $parts)), 0, 12);
    }

    private function restore(DisplayState $state, string $reason): DisplayState
    {
        return $this->write([
            'mode' => DisplayState::MODE_NORMAL,
            'brightness' => 100,
            'fade_ms' => max(0, (int) config('posters.dimming.fade_ms', 2000)),
            'reason' => $reason,
            'expires_at' => null,
        ], $state->getKey());
    }

    private function write(array $attributes, ?int $id = null): DisplayState
    {
        return DB::transaction(function () use ($attributes, $id) {
            $id ??= DisplayState::current()->getKey();

            /** @var DisplayState $state */
            $state = DisplayState::query()->lockForUpdate()->findOrFail($id);
            $state->fill($attributes);
            $state->version = (int) $state->version + 1;
            $state->save();

            return $state;
        });
    }

    private function clampBrightness(int $value): int
    {
        return max(0, min(100, $value));
    }
}
