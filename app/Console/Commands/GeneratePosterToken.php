<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GeneratePosterToken extends Command
{
    protected $signature = 'posters:token
                            {--write : Write the token to .env (replacing any existing POSTER_API_TOKEN)}
                            {--force : With --write, replace an existing token without asking}';

    protected $description = 'Generate a random API token for the control endpoints and print the .env line';

    public function handle(): int
    {
        $token = Str::random(40);
        $line = "POSTER_API_TOKEN={$token}";

        $this->line($line);

        if (! $this->option('write')) {
            $this->newLine();
            $this->comment('Add that line to .env (or re-run with --write), then run: php artisan config:clear');

            return self::SUCCESS;
        }

        $path = $this->laravel->environmentFilePath();

        if (! is_file($path)) {
            $this->error("No .env file found at {$path}. Copy .env.example first.");

            return self::FAILURE;
        }

        $contents = (string) file_get_contents($path);
        $pattern = '/^POSTER_API_TOKEN=.*$/m';

        if (preg_match('/^POSTER_API_TOKEN=(.+)$/m', $contents, $m) && trim($m[1]) !== ''
            && ! $this->option('force')
            && ! $this->confirm('A POSTER_API_TOKEN is already set. Replace it? (Home Assistant will need the new one)', false)) {
            $this->comment('Left .env unchanged.');

            return self::SUCCESS;
        }

        $contents = preg_match($pattern, $contents)
            ? preg_replace($pattern, $line, $contents, 1)
            : rtrim($contents, "\r\n").PHP_EOL.PHP_EOL.$line.PHP_EOL;

        file_put_contents($path, $contents);

        $this->info("Written to {$path}.");
        $this->comment('If you use config caching, run: php artisan config:clear');

        return self::SUCCESS;
    }
}
