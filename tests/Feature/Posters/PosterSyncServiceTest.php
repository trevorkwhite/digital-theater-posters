<?php

namespace Tests\Feature\Posters;

use App\Models\Poster;
use App\Models\SyncRun;
use App\Posters\Exceptions\SyncAlreadyRunningException;
use App\Posters\PosterSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class PosterSyncServiceTest extends PosterTestCase
{
    public function test_first_sync_adds_posters_and_downloads_images(): void
    {
        $this->fake->addItem('1')->addItem('2')->addItem('3', 'tag1', ['genres' => ['Action', 'Sci-Fi'], 'officialRating' => 'R']);

        $run = $this->sync();

        $this->assertSame(SyncRun::SUCCEEDED, $run->status);
        $this->assertSame(3, $run->items_seen);
        $this->assertSame(3, $run->items_added);
        $this->assertSame(0, $run->items_updated);
        $this->assertSame(3, $run->images_downloaded);
        $this->assertNotNull($run->finished_at);

        $poster = $this->poster('3');
        $this->assertSame(['Action', 'Sci-Fi'], $poster->genres);
        $this->assertSame('R', $poster->official_rating);
        $this->assertSame('image/png', $poster->image_mime);
        $this->assertSame(1, $poster->image_width);
        $this->assertSame(1, $poster->image_height);
        $this->assertTrue($poster->is_active);
        $this->assertNotNull($poster->last_seen_at);
        $this->assertStringStartsWith('fake/'.substr(sha1('3'), 0, 2).'/3-', $poster->image_path);
        $this->assertStringEndsWith('.png', $poster->image_path);
        Storage::disk('posters')->assertExists($poster->image_path);

        // No temp files left behind.
        $this->assertSame([], array_values(array_filter(
            Storage::disk('posters')->allFiles(),
            fn ($f) => str_ends_with($f, '.tmp')
        )));
    }

    public function test_resync_with_same_tag_does_not_redownload(): void
    {
        $this->fake->addItem('1')->addItem('2');
        $this->sync();

        $run = $this->sync();

        $this->assertSame(SyncRun::SUCCEEDED, $run->status);
        $this->assertSame(0, $run->images_downloaded);
        $this->assertSame(0, $run->items_added);
        $this->assertSame(0, $run->items_updated);
        $this->assertSame(1, $this->fake->fetchCount('1'));
        $this->assertSame(2, Poster::count());
    }

    public function test_force_images_redownloads_everything(): void
    {
        $this->fake->addItem('1');
        $this->sync();

        $run = $this->sync(force: true);

        $this->assertSame(1, $run->images_downloaded);
        $this->assertSame(2, $this->fake->fetchCount('1'));
        Storage::disk('posters')->assertExists($this->poster('1')->image_path);
    }

    public function test_metadata_change_counts_as_update(): void
    {
        $this->fake->addItem('1');
        $this->sync();

        $this->fake->items = [];
        $this->fake->addItem('1', 'tag1', ['title' => 'Renamed']);
        $run = $this->sync();

        $this->assertSame(1, $run->items_updated);
        $this->assertSame(0, $run->images_downloaded);
        $this->assertSame('Renamed', $this->poster('1')->title);
    }

    public function test_tag_change_redownloads_and_deletes_old_file(): void
    {
        $this->fake->addItem('1', 'old-tag');
        $this->sync();
        $oldPath = $this->poster('1')->image_path;

        $this->fake->setTag('1', 'new-tag');
        $run = $this->sync();

        $poster = $this->poster('1');
        $this->assertSame(1, $run->images_downloaded);
        $this->assertSame(1, $run->items_updated);
        $this->assertSame('new-tag', $poster->image_tag);
        $this->assertNotSame($oldPath, $poster->image_path);
        Storage::disk('posters')->assertExists($poster->image_path);
        Storage::disk('posters')->assertMissing($oldPath);
    }

    public function test_missing_file_is_redownloaded(): void
    {
        $this->fake->addItem('1');
        $this->sync();
        $path = $this->poster('1')->image_path;
        Storage::disk('posters')->delete($path);

        $run = $this->sync();

        $this->assertSame(1, $run->images_downloaded);
        $this->assertSame(2, $this->fake->fetchCount('1'));
        Storage::disk('posters')->assertExists($this->poster('1')->image_path);
    }

    public function test_one_image_failure_does_not_fail_the_run(): void
    {
        $this->fake->addItem('1')->addItem('2')->addItem('3')->failImageFor('2');

        $run = $this->sync();

        $this->assertSame(SyncRun::SUCCEEDED, $run->status);
        $this->assertSame(3, $run->items_seen);
        $this->assertSame(2, $run->images_downloaded);
        $this->assertSame(1, $run->images_failed);
        $this->assertNull($this->poster('2')->image_path);
        $this->assertSame(2, Poster::displayable()->count());
    }

    public function test_failed_redownload_keeps_the_old_image(): void
    {
        $this->fake->addItem('1', 'v1');
        $this->sync();
        $oldPath = $this->poster('1')->image_path;

        $this->fake->setTag('1', 'v2')->failImageFor('1');
        $run = $this->sync();

        $poster = $this->poster('1');
        $this->assertSame(SyncRun::SUCCEEDED, $run->status);
        $this->assertSame(1, $run->images_failed);
        $this->assertSame($oldPath, $poster->image_path);
        $this->assertSame('v1', $poster->image_tag, 'Old tag kept so the next sync retries.');
        Storage::disk('posters')->assertExists($oldPath);
    }

    public function test_invalid_image_bytes_are_rejected(): void
    {
        $this->fake->addItem('1');
        $this->fake->imageBytes['1'] = '<html>login required</html>';

        $run = $this->sync();

        $this->assertSame(SyncRun::SUCCEEDED, $run->status);
        $this->assertSame(1, $run->images_failed);
        $this->assertNull($this->poster('1')->image_path);
    }

    public function test_source_exception_marks_run_failed_and_deactivates_nothing(): void
    {
        $this->fake->addItem('1')->addItem('2')->addItem('3');
        $this->sync();

        $this->fake->failIterationAfter(1, new \RuntimeException('Emby is down'));
        $runs = [$this->sync(), $this->sync(), $this->sync()];

        foreach ($runs as $run) {
            $this->assertSame(SyncRun::FAILED, $run->status);
            $this->assertStringContainsString('Emby is down', $run->error);
            $this->assertSame(0, $run->items_deactivated);
            $this->assertNotNull($run->finished_at);
        }

        $this->assertSame(3, Poster::active()->count());
        $this->assertSame(0, (int) Poster::max('missed_syncs'));
    }

    public function test_source_failing_immediately_keeps_library(): void
    {
        $this->fake->addItem('1')->addItem('2');
        $this->sync();

        $this->fake->failIterationAfter(0);
        $run = $this->sync();

        $this->assertTrue($run->failed());
        $this->assertSame(2, Poster::displayable()->count());
    }

    public function test_deactivation_after_n_missed_syncs_and_reactivation(): void
    {
        config(['posters.sync.deactivate_after_missed_syncs' => 2]);
        $this->fake->addItem('1')->addItem('2');
        $this->sync();

        $this->fake->removeItem('2');

        $run = $this->sync();
        $this->assertSame(0, $run->items_deactivated);
        $this->assertTrue($this->poster('2')->is_active);
        $this->assertSame(1, $this->poster('2')->missed_syncs);

        $run = $this->sync();
        $this->assertSame(1, $run->items_deactivated);
        $this->assertFalse($this->poster('2')->is_active);
        $this->assertSame(2, $this->poster('2')->missed_syncs);
        $this->assertTrue($this->poster('1')->is_active);
        $this->assertSame(0, $this->poster('1')->missed_syncs);

        // Comes back.
        $this->fake->addItem('2');
        $run = $this->sync();
        $poster = $this->poster('2');
        $this->assertTrue($poster->is_active);
        $this->assertSame(0, $poster->missed_syncs);
        $this->assertSame(1, $run->items_updated);
        $this->assertSame(0, $run->items_added);
        $this->assertSame(1, $this->fake->fetchCount('2'), 'Cached image still valid, no re-download.');
    }

    public function test_empty_listing_does_not_deactivate_cached_library(): void
    {
        $this->fake->addItem('1');
        $this->sync();

        $this->fake->items = [];
        $this->sync();
        $this->sync();
        $run = $this->sync();

        $this->assertTrue($run->succeeded());
        $this->assertTrue($this->poster('1')->is_active);
    }

    public function test_other_sources_are_not_touched(): void
    {
        $other = $this->makePoster('other-1', ['source' => 'other']);
        $this->fake->addItem('1');
        $this->sync();
        $this->sync();
        $this->sync();

        $this->assertTrue($other->fresh()->is_active);
        $this->assertSame(0, $other->fresh()->missed_syncs);
    }

    public function test_duplicate_items_in_listing_are_counted_once(): void
    {
        $this->fake->addItem('1')->addItem('1')->addItem('2');

        $run = $this->sync();

        $this->assertSame(2, $run->items_seen);
        $this->assertSame(2, Poster::count());
    }

    public function test_large_library_is_batched(): void
    {
        for ($i = 1; $i <= PosterSyncService::CHUNK_SIZE * 2 + 17; $i++) {
            $this->fake->addItem((string) $i);
        }

        $run = $this->sync();

        $this->assertSame(PosterSyncService::CHUNK_SIZE * 2 + 17, $run->items_added);
        $this->assertSame(PosterSyncService::CHUNK_SIZE * 2 + 17, Poster::displayable()->count());
    }

    public function test_overlapping_sync_is_rejected_by_lock(): void
    {
        $this->fake->addItem('1');
        $lock = Cache::lock(PosterSyncService::lockName('fake'), 60);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(SyncAlreadyRunningException::class);
            $this->sync();
        } finally {
            $lock->release();
        }
    }

    public function test_stale_running_rows_are_closed_on_next_sync(): void
    {
        $stale = SyncRun::create(['source' => 'fake', 'status' => SyncRun::RUNNING, 'started_at' => now()->subDay()]);
        $this->fake->addItem('1');

        $this->sync();

        $this->assertSame(SyncRun::FAILED, $stale->fresh()->status);
    }
}
