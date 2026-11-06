<?php

namespace App\Posters;

use App\Models\Poster;
use App\Models\SyncRun;
use App\Posters\Contracts\PosterSource;
use App\Posters\Data\SourceItem;
use App\Posters\Exceptions\SyncAlreadyRunningException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Copies a PosterSource's library into the posters table and the local image
 * cache. Owns all persistence; sources only read from their media server.
 *
 * Safety rules (the display must never go blank because of a backend problem):
 *  - a failed image download keeps the previous image and never fails the run;
 *  - if iterating the source throws, the run is marked failed and nothing is
 *    deactivated;
 *  - a run that sees zero items does not deactivate anything either.
 */
class PosterSyncService
{
    /** Items are buffered and upserted in batches of this size. */
    public const CHUNK_SIZE = 200;

    /** Upper bound on how long a sync may hold its lock (first sync downloads every image). */
    public const LOCK_SECONDS = 3 * 60 * 60;

    /** Columns refreshed on every sync for existing rows. */
    private const UPSERT_UPDATE_COLUMNS = [
        'media_type', 'title', 'sort_title', 'year', 'overview', 'official_rating',
        'community_rating', 'runtime_minutes', 'genres', 'library_name', 'extra',
        'image_tag', 'image_path', 'image_mime', 'image_width', 'image_height',
        'is_active', 'missed_syncs', 'last_seen_at',
    ];

    private const METADATA_COLUMNS = [
        'media_type', 'title', 'sort_title', 'year', 'overview', 'official_rating',
        'community_rating', 'runtime_minutes', 'genres', 'library_name', 'extra',
    ];

    public static function lockName(string $sourceKey): string
    {
        return "posters:sync:{$sourceKey}";
    }

    /** Whether another process currently holds the sync lock for this source. */
    public function isRunning(string $sourceKey): bool
    {
        $lock = Cache::lock(self::lockName($sourceKey), 10);

        if ($lock->get()) {
            $lock->release();

            return false;
        }

        return true;
    }

    /**
     * @param  callable(int $itemsSeen): void|null  $progress  Called after each item (for CLI progress bars).
     *
     * @throws SyncAlreadyRunningException when another sync for this source holds the lock.
     */
    public function sync(PosterSource $source, bool $forceImages = false, ?callable $progress = null): SyncRun
    {
        $key = $source->key();
        $lock = Cache::lock(self::lockName($key), self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new SyncAlreadyRunningException($key);
        }

        try {
            return $this->run($source, $key, $forceImages, $progress);
        } finally {
            $lock->release();
        }
    }

    private function run(PosterSource $source, string $key, bool $forceImages, ?callable $progress): SyncRun
    {
        // We hold the lock, so any "running" row for this source is from a crashed process.
        SyncRun::query()->where('source', $key)->where('status', SyncRun::RUNNING)->update([
            'status' => SyncRun::FAILED,
            'error' => 'Interrupted before completion.',
            'finished_at' => now(),
        ]);

        $run = SyncRun::create([
            'source' => $key,
            'status' => SyncRun::RUNNING,
            'started_at' => now(),
        ]);

        $stats = [
            'items_seen' => 0,
            'items_added' => 0,
            'items_updated' => 0,
            'images_downloaded' => 0,
            'images_failed' => 0,
            'items_deactivated' => 0,
        ];

        $disk = Storage::disk(config('posters.images.disk', 'posters'));
        $now = Carbon::now();

        // source_item_id => poster id, for every poster this source has ever given us.
        $known = Poster::query()->where('source', $key)->pluck('id', 'source_item_id')->all();
        $seen = [];
        $buffer = [];

        try {
            foreach ($source->items() as $item) {
                if (! $item instanceof SourceItem || $item->sourceItemId === '') {
                    continue;
                }

                if (isset($seen[$item->sourceItemId])) {
                    continue; // same title reported twice (e.g. in two libraries)
                }

                $seen[$item->sourceItemId] = true;
                $stats['items_seen']++;
                $buffer[] = $item;

                if (count($buffer) >= self::CHUNK_SIZE) {
                    $this->processChunk($source, $key, $buffer, $disk, $forceImages, $stats, $now);
                    $buffer = [];
                    $run->fill($stats)->save();
                }

                if ($progress) {
                    $progress($stats['items_seen']);
                }
            }

            if ($buffer !== []) {
                $this->processChunk($source, $key, $buffer, $disk, $forceImages, $stats, $now);
            }

            // Only now do we know the listing was complete.
            $stats['items_deactivated'] = $this->handleMissing($key, $known, $seen);

            $run->fill($stats + [
                'status' => SyncRun::SUCCEEDED,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            Log::error("Poster sync for [{$key}] failed; keeping the cached library as-is.", [
                'exception' => $e,
            ]);

            $run->fill($stats + [
                'status' => SyncRun::FAILED,
                'error' => Str::limit(get_class($e).': '.$e->getMessage(), 2000),
                'finished_at' => now(),
            ])->save();
        }

        return $run;
    }

    /**
     * @param  list<SourceItem>  $items
     * @param  array<string, int>  $stats
     */
    private function processChunk(
        PosterSource $source,
        string $key,
        array $items,
        Filesystem $disk,
        bool $forceImages,
        array &$stats,
        Carbon $now,
    ): void {
        $existing = Poster::query()->toBase()
            ->where('source', $key)
            ->whereIn('source_item_id', array_map(fn (SourceItem $i) => $i->sourceItemId, $items))
            ->get()
            ->keyBy(fn ($row) => (string) $row->source_item_id);

        $rows = [];
        $obsoleteFiles = [];

        foreach ($items as $item) {
            $old = $existing->get($item->sourceItemId);
            $meta = $this->metadata($item);

            $image = [
                'image_tag' => $old?->image_tag,
                'image_path' => $old?->image_path,
                'image_mime' => $old?->image_mime,
                'image_width' => $old?->image_width,
                'image_height' => $old?->image_height,
            ];

            $needsImage = $forceImages
                || $old === null
                || (string) $old->image_tag !== $item->imageTag
                || empty($old->image_path)
                || ! $disk->exists($old->image_path);

            $downloaded = false;

            if ($needsImage) {
                $stored = $this->downloadImage($source, $key, $item, $disk);

                if ($stored !== null) {
                    $downloaded = true;
                    $stats['images_downloaded']++;

                    if (! empty($old?->image_path) && $old->image_path !== $stored['image_path']) {
                        $obsoleteFiles[] = $old->image_path;
                    }

                    $image = $stored;
                } else {
                    $stats['images_failed']++;
                }
            }

            if ($old === null) {
                $stats['items_added']++;
            } elseif ($downloaded || ! $old->is_active || $this->metadataChanged($old, $meta)) {
                $stats['items_updated']++;
            }

            $rows[] = [
                'source' => $key,
                'source_item_id' => $item->sourceItemId,
                ...$meta,
                ...$image,
                'is_active' => true,
                'missed_syncs' => 0,
                'last_seen_at' => $now,
            ];
        }

        Poster::query()->upsert($rows, ['source', 'source_item_id'], self::UPSERT_UPDATE_COLUMNS);

        // Delete replaced files only after the DB points at the new ones.
        foreach ($obsoleteFiles as $path) {
            try {
                $disk->delete($path);
            } catch (Throwable $e) {
                Log::warning("Could not delete replaced poster image [{$path}].", ['exception' => $e]);
            }
        }
    }

    /**
     * Download, validate and atomically store one image.
     *
     * @return array{image_tag: string, image_path: string, image_mime: string, image_width: int, image_height: int}|null
     */
    private function downloadImage(PosterSource $source, string $key, SourceItem $item, Filesystem $disk): ?array
    {
        $tmp = null;

        try {
            $fetched = $source->fetchImage($item);
            $bytes = $fetched->bytes;

            if ($bytes === '') {
                throw new RuntimeException('Source returned an empty image.');
            }

            $info = @getimagesizefromstring($bytes);

            if ($info === false) {
                throw new RuntimeException('Downloaded bytes are not a recognised image.');
            }

            $mime = ! empty($info['mime']) ? $info['mime'] : $fetched->mimeType;
            $path = $this->imagePath($key, $item, $this->extensionFor($mime));
            $tmp = $path.'.'.Str::random(8).'.tmp';

            if ($disk->put($tmp, $bytes) === false) {
                throw new RuntimeException("Could not write [{$tmp}].");
            }

            if ($disk->exists($path)) {
                $disk->delete($path); // forced re-download of an unchanged tag
            }

            if ($disk->move($tmp, $path) === false) {
                throw new RuntimeException("Could not move [{$tmp}] to [{$path}].");
            }

            $tmp = null;

            return [
                'image_tag' => $item->imageTag,
                'image_path' => $path,
                'image_mime' => $mime,
                'image_width' => (int) $info[0],
                'image_height' => (int) $info[1],
            ];
        } catch (Throwable $e) {
            Log::warning("Poster image download failed for [{$key}:{$item->sourceItemId}] \"{$item->title}\".", [
                'error' => $e->getMessage(),
            ]);

            if ($tmp !== null) {
                try {
                    $disk->delete($tmp);
                } catch (Throwable) {
                    // ignore
                }
            }

            return null;
        }
    }

    /** {source}/{sha1(id)[0..1]}/{id}-{hash(tag)}.{ext} */
    public function imagePath(string $key, SourceItem $item, string $extension): string
    {
        $safeId = preg_replace('/[^A-Za-z0-9_-]/', '_', $item->sourceItemId);
        $safeKey = preg_replace('/[^A-Za-z0-9_-]/', '_', $key);

        return sprintf(
            '%s/%s/%s-%s.%s',
            $safeKey,
            substr(sha1($item->sourceItemId), 0, 2),
            Str::limit($safeId, 100, ''),
            substr(sha1($item->imageTag), 0, 12),
            $extension,
        );
    }

    private function extensionFor(string $mime): string
    {
        return match (strtolower($mime)) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }

    /** @return array<string, mixed> */
    private function metadata(SourceItem $item): array
    {
        $rating = $item->officialRating !== null ? trim($item->officialRating) : null;

        return [
            'media_type' => mb_substr($item->mediaType ?: 'movie', 0, 32),
            'title' => mb_substr($item->title, 0, 500),
            'sort_title' => $item->sortTitle !== null ? mb_substr($item->sortTitle, 0, 500) : null,
            'year' => $item->year,
            'overview' => $item->overview,
            'official_rating' => $rating === '' || $rating === null ? null : mb_substr($rating, 0, 32),
            'community_rating' => $item->communityRating,
            'runtime_minutes' => $item->runtimeMinutes,
            'genres' => json_encode(array_values($item->genres), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'library_name' => $item->libraryName !== null ? mb_substr($item->libraryName, 0, 255) : null,
            'extra' => json_encode($item->extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /** @param  array<string, mixed>  $meta */
    private function metadataChanged(object $old, array $meta): bool
    {
        foreach (self::METADATA_COLUMNS as $column) {
            if ($this->normalise($column, $old->{$column} ?? null) !== $this->normalise($column, $meta[$column])) {
                return true;
            }
        }

        return false;
    }

    private function normalise(string $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($column) {
            'genres', 'extra' => is_string($value) ? json_decode($value, true) : $value,
            'year', 'runtime_minutes' => (int) $value,
            'community_rating' => round((float) $value, 2),
            default => (string) $value,
        };
    }

    /**
     * After a complete listing: bump missed_syncs for posters the source no
     * longer reports and deactivate those past the threshold.
     *
     * @param  array<string|int, int>  $known  source_item_id => poster id (before this run)
     * @param  array<string|int, true>  $seen
     */
    private function handleMissing(string $key, array $known, array $seen): int
    {
        $unseenIds = array_values(array_diff_key($known, $seen));

        if ($unseenIds === []) {
            return 0;
        }

        if ($seen === []) {
            Log::warning("Poster source [{$key}] returned no items; not marking ".count($unseenIds).' cached posters as missing.');

            return 0;
        }

        $threshold = max(1, (int) config('posters.sync.deactivate_after_missed_syncs', 2));
        $deactivated = 0;

        foreach (array_chunk($unseenIds, 500) as $chunk) {
            Poster::query()->toBase()->whereIn('id', $chunk)->increment('missed_syncs');

            $deactivated += Poster::query()->toBase()
                ->whereIn('id', $chunk)
                ->where('is_active', true)
                ->where('missed_syncs', '>=', $threshold)
                ->update(['is_active' => false, 'updated_at' => now()]);
        }

        return $deactivated;
    }
}
