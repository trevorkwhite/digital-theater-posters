<?php

namespace App\Console\Commands;

use App\Models\SyncRun;
use App\Posters\Exceptions\SyncAlreadyRunningException;
use App\Posters\PosterSourceManager;
use App\Posters\PosterSyncService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class SyncPosters extends Command
{
    protected $signature = 'posters:sync
        {--source= : Only sync this source key (default: every enabled source)}
        {--force-images : Re-download every image even if its tag is unchanged}';

    protected $description = 'Sync poster metadata and artwork from the enabled media sources into the local cache';

    public function handle(PosterSourceManager $manager, PosterSyncService $sync): int
    {
        try {
            $sources = $this->option('source')
                ? [$this->option('source') => $manager->source($this->option('source'))]
                : $manager->enabled();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($sources === []) {
            $this->components->warn('No poster sources are enabled (set POSTER_SOURCES in .env).');

            return self::FAILURE;
        }

        $failed = false;
        $rows = [];

        foreach ($sources as $key => $source) {
            $this->components->info("Syncing {$source->displayName()} [{$key}]".($this->option('force-images') ? ' (forcing images)' : ''));

            $bar = $this->output->createProgressBar();
            $bar->setFormat(' %current% items [%bar%] %elapsed:6s% %memory:6s%');

            try {
                $run = $sync->sync($source, (bool) $this->option('force-images'), fn () => $bar->advance());
            } catch (SyncAlreadyRunningException $e) {
                $bar->clear();
                $this->components->error($e->getMessage());
                $failed = true;
                $rows[] = [$key, 'locked', '-', '-', '-', '-', '-', '-', '-', 'Another sync is running'];

                continue;
            } catch (Throwable $e) {
                $bar->clear();
                $this->components->error($e->getMessage());
                $failed = true;
                $rows[] = [$key, 'error', '-', '-', '-', '-', '-', '-', '-', $e->getMessage()];

                continue;
            }

            $bar->finish();
            $this->newLine(2);

            if ($run->failed()) {
                $failed = true;
                $this->components->error("Sync of [{$key}] failed: {$run->error}");
                $this->components->warn('Nothing was deactivated; the display keeps showing the cached library.');
            }

            $rows[] = $this->row($run);
        }

        $this->table(
            ['Source', 'Status', 'Seen', 'Added', 'Updated', 'Images', 'Img failed', 'Deactivated', 'Duration', 'Error'],
            $rows,
        );

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<string|int> */
    private function row(SyncRun $run): array
    {
        $duration = $run->started_at && $run->finished_at
            ? $run->started_at->diffForHumans($run->finished_at, ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2])
            : '-';

        return [
            $run->source,
            $run->status,
            $run->items_seen,
            $run->items_added,
            $run->items_updated,
            $run->images_downloaded,
            $run->images_failed,
            $run->items_deactivated,
            $duration,
            (string) str($run->error ?? '')->limit(80),
        ];
    }
}
