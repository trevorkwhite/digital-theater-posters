<?php

namespace App\Posters\Contracts;

use App\Posters\Data\FetchedImage;
use App\Posters\Data\SourceItem;

/**
 * A pluggable provider of poster artwork (Emby today; Plex, Jellyfin, a local
 * folder, etc. later). Implementations are resolved by PosterSourceManager from
 * config/posters.php and receive their own config array in the constructor.
 *
 * Sources are read-only and stateless: they never touch the database. The
 * caching layer (PosterSyncService) owns persistence and image storage.
 */
interface PosterSource
{
    /**
     * @param  string  $key  The source key from config('posters.sources'), e.g. "emby".
     * @param  array<string, mixed>  $config  That source's config block.
     */
    public function __construct(string $key, array $config);

    /** Stable key used in the posters.source column, e.g. "emby". */
    public function key(): string;

    /** Human readable name for logs and status output, e.g. "Emby (Living Room Server)". */
    public function displayName(): string;

    /**
     * Verify the source is reachable and the credentials work.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array;

    /**
     * Every item in the configured library scope that has poster artwork.
     * Must be lazy (paginate internally and yield), so large libraries never
     * sit in memory at once.
     *
     * @return iterable<SourceItem>
     */
    public function items(): iterable;

    /**
     * Download the poster artwork for one item, applying the source's auth.
     * Implementations should request a size near config('posters.images.max_height').
     */
    public function fetchImage(SourceItem $item): FetchedImage;
}
