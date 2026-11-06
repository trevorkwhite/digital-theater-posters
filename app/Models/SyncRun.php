<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One execution of PosterSyncService::sync() for a single source.
 *
 * @property int $id
 * @property string $source
 * @property string $status running|succeeded|failed
 * @property int $items_seen
 * @property int $items_added
 * @property int $items_updated
 * @property int $images_downloaded
 * @property int $images_failed
 * @property int $items_deactivated
 * @property string|null $error
 */
class SyncRun extends Model
{
    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public $timestamps = false;

    protected $guarded = [];

    protected $attributes = [
        'status' => self::RUNNING,
        'items_seen' => 0,
        'items_added' => 0,
        'items_updated' => 0,
        'images_downloaded' => 0,
        'images_failed' => 0,
        'items_deactivated' => 0,
    ];

    protected function casts(): array
    {
        return [
            'items_seen' => 'integer',
            'items_added' => 'integer',
            'items_updated' => 'integer',
            'images_downloaded' => 'integer',
            'images_failed' => 'integer',
            'items_deactivated' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function succeeded(): bool
    {
        return $this->status === self::SUCCEEDED;
    }

    public function failed(): bool
    {
        return $this->status === self::FAILED;
    }

    public function scopeForSource(Builder $query, string $source): void
    {
        $query->where('source', $source);
    }

    /** Most recent run for a source, if any. */
    public static function latestFor(string $source): ?self
    {
        return static::query()->where('source', $source)->orderByDesc('id')->first();
    }
}
