<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single-row state of the poster screen.
 *
 * @property int $id
 * @property string $mode normal|dim|black
 * @property int $brightness 0-100
 * @property int $fade_ms
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property int $version
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class DisplayState extends Model
{
    public const MODE_NORMAL = 'normal';

    public const MODE_DIM = 'dim';

    public const MODE_BLACK = 'black';

    public const MODES = [self::MODE_NORMAL, self::MODE_DIM, self::MODE_BLACK];

    protected $fillable = ['mode', 'brightness', 'fade_ms', 'reason', 'expires_at', 'version'];

    protected function casts(): array
    {
        return [
            'brightness' => 'integer',
            'fade_ms' => 'integer',
            'version' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /** The single row, created on first use (mode normal, brightness 100). */
    public static function current(): self
    {
        return static::query()->orderBy('id')->first()
            ?? static::query()->firstOrCreate(['id' => 1], [
                'mode' => self::MODE_NORMAL,
                'brightness' => 100,
                'fade_ms' => (int) config('posters.dimming.fade_ms', 2000),
                'version' => 1,
            ]);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
