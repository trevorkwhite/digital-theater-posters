<?php

namespace App\Posters;

use App\Models\Poster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Chooses the next poster to display as a "shuffle bag": random order, no
 * repeats until every displayable poster has been shown once.
 *
 * The bag is keyed on show_count: we pick uniformly at random among the
 * displayable posters with the lowest show_count, then increment it. Every
 * poster therefore gets shown once before any is shown twice, regardless of
 * clock precision. Newly synced posters (show_count 0) naturally go to the
 * front of the queue.
 *
 * Cost per call: MIN(), COUNT() and one OFFSET lookup on the (is_active,
 * show_count) index, so it stays cheap with thousands of rows (no ORDER BY RAND()).
 */
class PosterPicker
{
    private const MAX_ATTEMPTS = 5;

    /** @param  array<int|string>  $excludeIds  Recently shown IDs the client wants to avoid. */
    public function next(array $excludeIds = []): ?Poster
    {
        $excludeIds = array_values(array_unique(array_filter(
            array_map('intval', $excludeIds),
            fn (int $id) => $id > 0
        )));

        $poster = null;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $poster = $this->pick($excludeIds);

            if ($poster === null && $excludeIds !== []) {
                // Everything displayable is excluded (tiny library): ignore the exclusions.
                $poster = $this->pick([]);
            }

            if ($poster === null) {
                return null;
            }

            if ($this->markShown($poster, true)) {
                return $poster;
            }
            // Another display took this one in the meantime; pick again.
        }

        $this->markShown($poster, false);

        return $poster;
    }

    /** @param  list<int>  $excludeIds */
    private function pick(array $excludeIds): ?Poster
    {
        $base = fn (): Builder => Poster::query()
            ->displayable()
            ->when($excludeIds !== [], fn (Builder $q) => $q->whereNotIn('id', $excludeIds));

        $min = $base()->min('show_count');

        if ($min === null) {
            return null;
        }

        $count = $base()->where('show_count', $min)->count();

        if ($count === 0) {
            return null;
        }

        return $base()
            ->where('show_count', $min)
            ->orderBy('id')
            ->offset(random_int(0, $count - 1))
            ->limit(1)
            ->first();
    }

    /**
     * Increment show_count and stamp last_shown_at. With $optimistic the update
     * only applies if nobody else bumped the row since we read it.
     */
    private function markShown(Poster $poster, bool $optimistic): bool
    {
        $now = now();

        $affected = Poster::query()->toBase()
            ->where('id', $poster->getKey())
            ->when($optimistic, fn ($q) => $q->where('show_count', $poster->show_count))
            ->update([
                'show_count' => DB::raw('show_count + 1'),
                'last_shown_at' => $now,
            ]);

        if ($affected === 0) {
            return false;
        }

        $poster->forceFill([
            'show_count' => $poster->show_count + 1,
            'last_shown_at' => $now,
        ])->syncOriginal();

        return true;
    }
}
