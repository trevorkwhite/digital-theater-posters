<?php

namespace Tests\Feature\Display;

use Tests\TestCase;

class PosterTokenCommandTest extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir && is_dir($this->dir)) {
            @unlink($this->dir.'/.env');
            @rmdir($this->dir);
        }

        parent::tearDown();
    }

    private function useTempEnv(string $contents): string
    {
        $this->dir = sys_get_temp_dir().'/dtp-env-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/.env', $contents);
        $this->app->useEnvironmentPath($this->dir);

        return $this->dir.'/.env';
    }

    public function test_prints_env_line_without_writing(): void
    {
        $path = $this->useTempEnv("APP_NAME=x\n");

        $this->artisan('posters:token')
            ->expectsOutputToContain('POSTER_API_TOKEN=')
            ->assertSuccessful();

        $this->assertSame("APP_NAME=x\n", file_get_contents($path));
    }

    public function test_write_appends_or_replaces_token(): void
    {
        $path = $this->useTempEnv("APP_NAME=x\nPOSTER_API_TOKEN=\n");

        $this->artisan('posters:token', ['--write' => true])->assertSuccessful();

        $this->assertMatchesRegularExpression('/^POSTER_API_TOKEN=[A-Za-z0-9]{40}$/m', file_get_contents($path));
        $this->assertSame(1, substr_count(file_get_contents($path), 'POSTER_API_TOKEN='));
    }

    public function test_write_asks_before_replacing_existing_token(): void
    {
        $path = $this->useTempEnv("POSTER_API_TOKEN=keepme\n");

        $this->artisan('posters:token', ['--write' => true])
            ->expectsConfirmation('A POSTER_API_TOKEN is already set. Replace it? (Home Assistant will need the new one)', 'no')
            ->assertSuccessful();

        $this->assertSame("POSTER_API_TOKEN=keepme\n", file_get_contents($path));

        $this->artisan('posters:token', ['--write' => true, '--force' => true])->assertSuccessful();
        $this->assertStringNotContainsString('keepme', file_get_contents($path));
    }
}
