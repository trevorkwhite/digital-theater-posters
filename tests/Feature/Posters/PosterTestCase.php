<?php

namespace Tests\Feature\Posters;

use App\Models\Poster;
use App\Posters\PosterSourceManager;
use App\Posters\PosterSyncService;
use App\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakePosterSource;
use Tests\TestCase;

abstract class PosterTestCase extends TestCase
{
    use RefreshDatabase;

    protected FakePosterSource $fake;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('posters');

        config([
            'posters.images.disk' => 'posters',
            'posters.enabled_sources' => ['fake'],
            'posters.sources.fake' => ['driver' => FakePosterSource::class],
            'posters.sync.deactivate_after_missed_syncs' => 2,
            'posters.filters.exclude_ratings' => [],
        ]);

        $this->fake = FakePosterSource::make('fake');

        $manager = $this->app->make(PosterSourceManager::class);
        $manager->extend('fake', $this->fake);
        // Make sure everything resolves the same manager even if it is not a singleton yet.
        $this->app->instance(PosterSourceManager::class, $manager);
    }

    protected function sync(bool $force = false): SyncRun
    {
        return $this->app->make(PosterSyncService::class)->sync($this->fake, $force);
    }

    protected function poster(string $id): Poster
    {
        return Poster::where('source', 'fake')->where('source_item_id', $id)->firstOrFail();
    }

    /** Create a displayable poster directly (no sync), with an image on the fake disk. */
    protected function makePoster(string $id, array $attributes = []): Poster
    {
        $path = "fake/xx/{$id}.png";
        Storage::disk('posters')->put($path, FakePosterSource::png());

        return Poster::create(array_merge([
            'source' => 'fake',
            'source_item_id' => $id,
            'title' => "Movie {$id}",
            'image_tag' => "tag-{$id}",
            'image_path' => $path,
            'image_mime' => 'image/png',
            'image_width' => 1,
            'image_height' => 1,
            'official_rating' => 'PG',
            'is_active' => true,
        ], $attributes));
    }
}
