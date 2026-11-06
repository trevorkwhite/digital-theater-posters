<?php

namespace App\Posters;

use App\Posters\Contracts\PosterSource;
use InvalidArgumentException;

/**
 * Resolves PosterSource plugins from config/posters.php.
 * Registered as a singleton in AppServiceProvider.
 */
class PosterSourceManager
{
    /** @var array<string, PosterSource> */
    private array $resolved = [];

    public function source(string $key): PosterSource
    {
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $config = config("posters.sources.{$key}");

        if (! is_array($config) || empty($config['driver'])) {
            throw new InvalidArgumentException("Poster source [{$key}] is not configured in config/posters.php.");
        }

        $driver = $config['driver'];

        if (! class_exists($driver) || ! is_subclass_of($driver, PosterSource::class)) {
            throw new InvalidArgumentException("Poster source [{$key}] driver [{$driver}] must implement ".PosterSource::class.'.');
        }

        return $this->resolved[$key] = new $driver($key, $config);
    }

    /** @return array<string, PosterSource> */
    public function enabled(): array
    {
        $sources = [];

        foreach (config('posters.enabled_sources', []) as $key) {
            $sources[$key] = $this->source($key);
        }

        return $sources;
    }

    /** Swap in a fake for tests. */
    public function extend(string $key, PosterSource $source): void
    {
        $this->resolved[$key] = $source;
    }
}
