<?php

namespace Tests\Feature\Posters;

use App\Models\Poster;
use App\Posters\PosterSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakePosterSource;

class PosterApiTest extends PosterTestCase
{
    public function test_next_returns_404_json_when_empty(): void
    {
        $this->getJson('/api/posters/next')
            ->assertNotFound()
            ->assertExactJson(['message' => 'No posters cached yet. Run php artisan posters:sync.']);
    }

    public function test_next_returns_the_documented_shape(): void
    {
        $poster = $this->makePoster('1', ['year' => 1999, 'runtime_minutes' => 136, 'official_rating' => 'R']);

        $response = $this->getJson('/api/posters/next')->assertOk();

        $response->assertExactJson([
            'id' => $poster->id,
            'title' => 'Movie 1',
            'year' => 1999,
            'official_rating' => 'R',
            'runtime_minutes' => 136,
            'image_url' => route('posters.image', ['poster' => $poster->id, 'v' => 'tag-1']),
        ]);
        $this->assertStringContainsString('v=tag-1', $response->json('image_url'));
        $this->assertSame(1, $poster->fresh()->show_count);
    }

    public function test_next_honours_exclude(): void
    {
        $a = $this->makePoster('a');
        $b = $this->makePoster('b');

        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/posters/next?exclude[]='.$a->id)->assertOk()->assertJsonPath('id', $b->id);
        }
    }

    public function test_next_validates_exclude(): void
    {
        $this->makePoster('a');

        $this->getJson('/api/posters/next?exclude[]=abc')->assertUnprocessable();
    }

    public function test_next_respects_rating_filter(): void
    {
        config(['posters.filters.exclude_ratings' => ['R']]);
        $this->makePoster('r', ['official_rating' => 'R']);

        $this->getJson('/api/posters/next')->assertNotFound();
    }

    public function test_image_endpoint_serves_file_with_cache_headers(): void
    {
        $poster = $this->makePoster('1', ['image_tag' => 'abc123']);

        $response = $this->get($poster->image_url);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('ETag', '"abc123"');
        $cache = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cache);
        $this->assertStringContainsString('max-age=31536000', $cache);
        $this->assertStringContainsString('immutable', $cache);
        $this->assertSame(FakePosterSource::png(), $response->streamedContent());
    }

    public function test_image_endpoint_returns_304_on_matching_etag(): void
    {
        $poster = $this->makePoster('1', ['image_tag' => 'abc123']);

        $response = $this->get('/posters/'.$poster->id.'/image', ['If-None-Match' => '"abc123"']);

        $response->assertStatus(304);
        $response->assertHeader('ETag', '"abc123"');
    }

    public function test_image_endpoint_404_when_file_missing(): void
    {
        $poster = $this->makePoster('1');
        Storage::disk('posters')->delete($poster->image_path);

        $this->get('/posters/'.$poster->id.'/image')->assertNotFound();

        $noImage = $this->makePoster('2', ['image_path' => null]);
        $this->get('/posters/'.$noImage->id.'/image')->assertNotFound();
    }

    public function test_image_endpoint_404_for_unknown_poster(): void
    {
        $this->get('/posters/999999/image')->assertNotFound();
    }

    public function test_synced_poster_round_trip(): void
    {
        $this->fake->addItem('42');
        $this->artisan('posters:sync')->assertExitCode(0);

        $url = $this->getJson('/api/posters/next')->assertOk()->json('image_url');
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->assertSame(1, Poster::count());
    }

    public function test_sync_endpoint_runs_and_returns_runs(): void
    {
        config(['posters.api_token' => 'secret-token']);
        $this->fake->addItem('1')->addItem('2');

        $this->withToken('secret-token')
            ->postJson('/api/sync')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('runs.0.source', 'fake')
            ->assertJsonPath('runs.0.status', 'succeeded')
            ->assertJsonPath('runs.0.items_added', 2);
    }

    public function test_sync_endpoint_reports_failed_run(): void
    {
        config(['posters.api_token' => 'secret-token']);
        $this->fake->addItem('1')->failIterationAfter(0);

        $this->withToken('secret-token')
            ->postJson('/api/sync')
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('runs.0.status', 'failed');
    }

    public function test_sync_endpoint_unknown_source_is_422(): void
    {
        config(['posters.api_token' => 'secret-token']);

        $this->withToken('secret-token')->postJson('/api/sync?source=nope')->assertUnprocessable();
    }

    public function test_sync_endpoint_returns_409_while_a_sync_is_running(): void
    {
        config(['posters.api_token' => 'secret-token']);
        $this->fake->addItem('1');

        $lock = Cache::lock(PosterSyncService::lockName('fake'), 60);
        $this->assertTrue($lock->get());

        try {
            $this->withToken('secret-token')->postJson('/api/sync')->assertStatus(409)->assertJsonPath('source', 'fake');
            $this->assertSame(0, Poster::count());
        } finally {
            $lock->release();
        }

        $this->withToken('secret-token')->postJson('/api/sync')->assertOk();
    }
}
