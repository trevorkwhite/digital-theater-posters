<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * A cached poster from a PosterSource. Rows are written by PosterSyncService;
 * the image lives on the "posters" disk at image_path.
 *
 * @property int $id
 * @property string $source
 * @property string $source_item_id
 * @property string $title
 * @property string|null $image_tag
 * @property string|null $image_path
 * @property string|null $image_mime
 * @property bool $is_active
 * @property int $missed_syncs
 * @property int $show_count
 * @property-read string|null $image_url
 */
class Poster extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'media_type' => 'movie',
        'is_active' => true,
        'missed_syncs' => 0,
        'show_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'community_rating' => 'float',
            'runtime_minutes' => 'integer',
            'genres' => 'array',
            'extra' => 'array',
            'image_width' => 'integer',
            'image_height' => 'integer',
            'is_active' => 'boolean',
            'missed_syncs' => 'integer',
            'show_count' => 'integer',
            'last_seen_at' => 'datetime',
            'last_shown_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Posters the display may show: active, image cached, and not filtered out
     * by config('posters.filters.exclude_ratings'). Unrated titles (null/empty
     * official_rating) are shown unless the list contains NR / Unrated / Not Rated.
     */
    public function scopeDisplayable(Builder $query): void
    {
        $query->where('is_active', true)->whereNotNull('image_path')->where('image_path', '<>', '');

        $excluded = array_values(array_filter(
            array_map(fn ($r) => trim((string) $r), (array) config('posters.filters.exclude_ratings', [])),
            fn ($r) => $r !== ''
        ));

        if ($excluded === []) {
            return;
        }

        $excludeUnrated = collect($excluded)
            ->contains(fn ($r) => in_array(strtoupper($r), ['NR', 'UNRATED', 'NOT RATED'], true));

        if ($excludeUnrated) {
            $excluded[] = '';
            $query->whereNotNull('official_rating')->whereNotIn('official_rating', $excluded);
        } else {
            $query->where(fn (Builder $q) => $q
                ->whereNull('official_rating')
                ->orWhereNotIn('official_rating', $excluded));
        }
    }

    /** URL of the cached image, cache-busted with ?v=image_tag. */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (! $this->exists || empty($this->image_path)) {
                return null;
            }

            return route('posters.image', array_filter([
                'poster' => $this->getKey(),
                'v' => $this->image_tag,
            ], fn ($v) => $v !== null && $v !== ''));
        });
    }
}
