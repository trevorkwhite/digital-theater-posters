<?php

namespace Tests\Fakes;

use App\Posters\Contracts\PosterSource;
use App\Posters\Data\FetchedImage;
use App\Posters\Data\SourceItem;
use RuntimeException;
use Throwable;

/**
 * In-memory PosterSource for tests. Register with:
 *   app(PosterSourceManager::class)->extend('fake', $fake);
 */
class FakePosterSource implements PosterSource
{
    /** @var list<SourceItem> */
    public array $items = [];

    /** @var array<string, true> sourceItemIds whose fetchImage() throws */
    public array $failingImages = [];

    /** @var array<string, string> sourceItemId => raw bytes override */
    public array $imageBytes = [];

    /** Throw this after yielding $throwAfter items (null = never). */
    public ?Throwable $iterationException = null;

    public int $throwAfter = 0;

    /** @var array<string, int> sourceItemId => number of fetchImage() calls */
    public array $fetches = [];

    public array $connectionResult = ['ok' => true, 'message' => 'Connected to fake source.'];

    public function __construct(private string $key = 'fake', private array $config = []) {}

    public static function make(string $key = 'fake'): self
    {
        return new self($key, ['driver' => self::class]);
    }

    /** 1x1 PNG. */
    public static function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    }

    /** @param  array<string, mixed>  $overrides */
    public function addItem(string $id, string $tag = 'tag1', array $overrides = []): self
    {
        $this->items[] = new SourceItem(...array_merge([
            'sourceItemId' => $id,
            'title' => "Movie {$id}",
            'imageTag' => $tag,
            'year' => 2000,
            'officialRating' => 'PG',
            'runtimeMinutes' => 100,
            'genres' => ['Drama'],
        ], $overrides));

        return $this;
    }

    public function removeItem(string $id): self
    {
        $this->items = array_values(array_filter($this->items, fn (SourceItem $i) => $i->sourceItemId !== $id));

        return $this;
    }

    public function setTag(string $id, string $tag): self
    {
        $this->items = array_map(function (SourceItem $i) use ($id, $tag) {
            if ($i->sourceItemId !== $id) {
                return $i;
            }

            return new SourceItem(
                sourceItemId: $i->sourceItemId, title: $i->title, imageTag: $tag, mediaType: $i->mediaType,
                sortTitle: $i->sortTitle, year: $i->year, overview: $i->overview, officialRating: $i->officialRating,
                communityRating: $i->communityRating, runtimeMinutes: $i->runtimeMinutes, genres: $i->genres,
                libraryName: $i->libraryName, extra: $i->extra,
            );
        }, $this->items);

        return $this;
    }

    public function failImageFor(string $id): self
    {
        $this->failingImages[$id] = true;

        return $this;
    }

    public function failIterationAfter(int $count, ?Throwable $e = null): self
    {
        $this->throwAfter = $count;
        $this->iterationException = $e ?? new RuntimeException('Fake source is down.');

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function displayName(): string
    {
        return 'Fake source';
    }

    public function testConnection(): array
    {
        return $this->connectionResult;
    }

    public function items(): iterable
    {
        $yielded = 0;

        foreach ($this->items as $item) {
            if ($this->iterationException && $yielded >= $this->throwAfter) {
                throw $this->iterationException;
            }

            yield $item;
            $yielded++;
        }

        if ($this->iterationException) {
            throw $this->iterationException;
        }
    }

    public function fetchImage(SourceItem $item): FetchedImage
    {
        $this->fetches[$item->sourceItemId] = ($this->fetches[$item->sourceItemId] ?? 0) + 1;

        if (isset($this->failingImages[$item->sourceItemId])) {
            throw new RuntimeException("Image for {$item->sourceItemId} unavailable.");
        }

        return new FetchedImage($this->imageBytes[$item->sourceItemId] ?? self::png(), 'image/png');
    }

    public function fetchCount(?string $id = null): int
    {
        return $id === null ? array_sum($this->fetches) : ($this->fetches[$id] ?? 0);
    }
}
