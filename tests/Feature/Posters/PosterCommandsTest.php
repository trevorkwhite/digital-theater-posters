<?php

namespace Tests\Feature\Posters;

use App\Models\SyncRun;
use App\Posters\PosterSyncService;
use Illuminate\Support\Facades\Cache;

class PosterCommandsTest extends PosterTestCase
{
    public function test_sync_command_succeeds(): void
    {
        $this->fake->addItem('1')->addItem('2');

        $this->artisan('posters:sync')->assertExitCode(0);

        $this->assertSame(SyncRun::SUCCEEDED, SyncRun::latestFor('fake')->status);
        $this->assertSame(2, SyncRun::latestFor('fake')->items_added);
    }

    public function test_sync_command_with_source_option(): void
    {
        $this->fake->addItem('1');

        $this->artisan('posters:sync', ['--source' => 'fake'])->assertExitCode(0);
    }

    public function test_sync_command_force_images(): void
    {
        $this->fake->addItem('1');
        $this->artisan('posters:sync')->assertExitCode(0);
        $this->artisan('posters:sync', ['--force-images' => true])->assertExitCode(0);

        $this->assertSame(2, $this->fake->fetchCount('1'));
    }

    public function test_sync_command_fails_when_source_throws(): void
    {
        $this->fake->addItem('1')->failIterationAfter(0);

        $this->artisan('posters:sync')->assertExitCode(1);
        $this->assertSame(SyncRun::FAILED, SyncRun::latestFor('fake')->status);
    }

    public function test_sync_command_fails_for_unknown_source(): void
    {
        $this->artisan('posters:sync', ['--source' => 'nope'])->assertExitCode(1);
    }

    public function test_sync_command_fails_when_locked(): void
    {
        $lock = Cache::lock(PosterSyncService::lockName('fake'), 60);
        $lock->get();

        try {
            $this->artisan('posters:sync')->assertExitCode(1);
        } finally {
            $lock->release();
        }
    }

    public function test_test_source_command(): void
    {
        $this->artisan('posters:test-source')->assertExitCode(0);
        $this->artisan('posters:test-source', ['source' => 'fake'])->assertExitCode(0);

        $this->fake->connectionResult = ['ok' => false, 'message' => 'Unauthorized'];
        $this->artisan('posters:test-source')->assertExitCode(1);

        $this->artisan('posters:test-source', ['source' => 'nope'])->assertExitCode(1);
    }
}
