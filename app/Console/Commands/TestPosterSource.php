<?php

namespace App\Console\Commands;

use App\Posters\PosterSourceManager;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class TestPosterSource extends Command
{
    protected $signature = 'posters:test-source {source? : Source key (default: every enabled source)}';

    protected $description = 'Check that the configured poster source(s) are reachable and the credentials work';

    public function handle(PosterSourceManager $manager): int
    {
        $key = $this->argument('source');

        try {
            $sources = $key ? [$key => $manager->source($key)] : $manager->enabled();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($sources === []) {
            $this->components->warn('No poster sources are enabled (set POSTER_SOURCES in .env).');

            return self::FAILURE;
        }

        $allOk = true;
        $rows = [];

        foreach ($sources as $sourceKey => $source) {
            try {
                $result = $source->testConnection();
                $ok = (bool) ($result['ok'] ?? false);
                $message = (string) ($result['message'] ?? '');
            } catch (Throwable $e) {
                $ok = false;
                $message = $e->getMessage();
            }

            $allOk = $allOk && $ok;
            $rows[] = [$sourceKey, $source->displayName(), $ok ? '<info>OK</info>' : '<error>FAIL</error>', $message];
        }

        $this->table(['Source', 'Name', 'Result', 'Message'], $rows);

        return $allOk ? self::SUCCESS : self::FAILURE;
    }
}
