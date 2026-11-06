<?php

namespace App\Posters\Data;

/**
 * One title as reported by a PosterSource. Source-agnostic: every source maps
 * its own API shape into this.
 */
final readonly class SourceItem
{
    /**
     * @param  string  $sourceItemId  The item's ID inside the source (Emby ItemId).
     * @param  string  $imageTag  Changes whenever the artwork changes; used to skip re-downloads.
     * @param  list<string>  $genres
     * @param  array<string, mixed>  $extra  Source-specific extras (provider IDs, library name...).
     */
    public function __construct(
        public string $sourceItemId,
        public string $title,
        public string $imageTag,
        public string $mediaType = 'movie',
        public ?string $sortTitle = null,
        public ?int $year = null,
        public ?string $overview = null,
        public ?string $officialRating = null,
        public ?float $communityRating = null,
        public ?int $runtimeMinutes = null,
        public array $genres = [],
        public ?string $libraryName = null,
        public array $extra = [],
    ) {}
}
