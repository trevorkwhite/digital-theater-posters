<?php

namespace Tests\Feature\Display;

use App\Models\DisplayState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DisplayStateApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token-0123456789abcdefghijklmnopqrstuv';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'posters.api_token' => self::TOKEN,
            'posters.dimming.dim_brightness' => 10,
            'posters.dimming.fade_ms' => 2000,
            'posters.dimming.on_play' => 'black',
            'posters.dimming.on_pause' => 'dim',
            'posters.dimming.on_stop' => 'normal',
            'posters.dimming.auto_restore_minutes' => 240,
        ]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.self::TOKEN];
    }

    public function test_get_state_returns_normal_defaults_and_creates_single_row(): void
    {
        $this->getJson('/api/display/state')
            ->assertOk()
            ->assertJson(['mode' => 'normal', 'brightness' => 100, 'version' => 1])
            ->assertJsonStructure(['mode', 'brightness', 'fade_ms', 'version', 'updated_at', 'app_version']);

        $this->getJson('/api/display/state')->assertOk();

        $this->assertSame(1, DisplayState::count());
    }

    public function test_set_dim_uses_config_brightness_and_bumps_version(): void
    {
        $this->postJson('/api/display/state', ['mode' => 'dim'], $this->auth())
            ->assertOk()
            ->assertJson(['mode' => 'dim', 'brightness' => 10, 'fade_ms' => 2000, 'version' => 2]);

        $this->getJson('/api/display/state')->assertJson(['mode' => 'dim', 'brightness' => 10, 'version' => 2]);
    }

    public function test_black_forces_zero_brightness(): void
    {
        $this->postJson('/api/display/state', ['mode' => 'black', 'brightness' => 70], $this->auth())
            ->assertOk()
            ->assertJson(['mode' => 'black', 'brightness' => 0]);
    }

    public function test_custom_brightness_fade_and_reason(): void
    {
        $this->postJson('/api/display/state', [
            'mode' => 'DIM',
            'brightness' => 35,
            'fade_ms' => 500,
            'reason' => 'lights down',
        ], $this->auth())
            ->assertOk()
            ->assertJson(['mode' => 'dim', 'brightness' => 35, 'fade_ms' => 500, 'reason' => 'lights down']);
    }

    public function test_every_change_bumps_version(): void
    {
        $this->postJson('/api/display/state', ['mode' => 'black'], $this->auth())->assertJson(['version' => 2]);
        $this->postJson('/api/display/state', ['mode' => 'black'], $this->auth())->assertJson(['version' => 3]);
        $this->postJson('/api/display/state', ['mode' => 'normal'], $this->auth())->assertJson(['version' => 4, 'brightness' => 100]);
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing mode' => [[], 'mode'],
            'bad mode' => [['mode' => 'off'], 'mode'],
            'brightness too high' => [['mode' => 'dim', 'brightness' => 101], 'brightness'],
            'brightness negative' => [['mode' => 'dim', 'brightness' => -1], 'brightness'],
            'brightness not int' => [['mode' => 'dim', 'brightness' => 'half'], 'brightness'],
            'fade negative' => [['mode' => 'dim', 'fade_ms' => -5], 'fade_ms'],
            'fade too long' => [['mode' => 'dim', 'fade_ms' => 600001], 'fade_ms'],
            'expiry negative' => [['mode' => 'dim', 'expires_in_minutes' => -1], 'expires_in_minutes'],
            'reason too long' => [['mode' => 'dim', 'reason' => str_repeat('x', 256)], 'reason'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_validation_errors(array $payload, string $field): void
    {
        $this->postJson('/api/display/state', $payload, $this->auth())
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);
    }

    public function test_missing_token_is_401(): void
    {
        $this->postJson('/api/display/state', ['mode' => 'black'])->assertStatus(401);
        $this->postJson('/api/display/playback', ['event' => 'play'])->assertStatus(401);
        $this->postJson('/api/sync')->assertStatus(401);

        $this->assertSame('normal', DisplayState::current()->mode);
    }

    public function test_wrong_token_is_401(): void
    {
        $this->postJson('/api/display/state', ['mode' => 'black'], ['Authorization' => 'Bearer nope'])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function test_token_in_query_string_is_accepted(): void
    {
        $this->postJson('/api/display/state?token='.self::TOKEN, ['mode' => 'black'])
            ->assertOk()
            ->assertJson(['mode' => 'black']);

        $this->postJson('/api/display/state?token=wrong', ['mode' => 'dim'])->assertStatus(401);
    }

    public function test_control_api_disabled_without_token_is_503(): void
    {
        config(['posters.api_token' => null]);

        $this->postJson('/api/display/state', ['mode' => 'black'], $this->auth())->assertStatus(503);
        $this->postJson('/api/display/playback', ['event' => 'play'])->assertStatus(503);
        $this->postJson('/api/sync')->assertStatus(503);

        // Public endpoints still work.
        $this->getJson('/api/display/state')->assertOk();
    }

    public static function playbackEvents(): array
    {
        return [
            'play' => ['play', 'black', 0],
            'resume' => ['resume', 'black', 0],
            'pause' => ['pause', 'dim', 10],
            'stop' => ['stop', 'normal', 100],
        ];
    }

    #[DataProvider('playbackEvents')]
    public function test_playback_maps_events_via_config(string $event, string $mode, int $brightness): void
    {
        $this->postJson('/api/display/playback', ['event' => $event], $this->auth())
            ->assertOk()
            ->assertJson(['mode' => $mode, 'brightness' => $brightness, 'event' => $event, 'reason' => "playback:{$event}"]);
    }

    public function test_playback_mapping_is_configurable(): void
    {
        config(['posters.dimming.on_play' => 'dim', 'posters.dimming.on_pause' => 'normal', 'posters.dimming.dim_brightness' => 25]);

        $this->postJson('/api/display/playback', ['event' => 'play'], $this->auth())->assertJson(['mode' => 'dim', 'brightness' => 25]);
        $this->postJson('/api/display/playback', ['event' => 'pause'], $this->auth())->assertJson(['mode' => 'normal', 'brightness' => 100]);
    }

    public function test_playback_invalid_event_is_422(): void
    {
        $this->postJson('/api/display/playback', ['event' => 'rewind'], $this->auth())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['event']);

        $this->postJson('/api/display/playback', [], $this->auth())->assertStatus(422);
    }

    public function test_non_normal_state_gets_default_expiry_and_normal_has_none(): void
    {
        $this->freezeTime();

        $this->postJson('/api/display/state', ['mode' => 'black'], $this->auth())
            ->assertJson(['expires_at' => now()->addMinutes(240)->toIso8601String()]);

        $this->postJson('/api/display/state', ['mode' => 'normal', 'expires_in_minutes' => 5], $this->auth())
            ->assertJson(['expires_at' => null]);
    }

    public function test_expires_in_minutes_zero_means_never(): void
    {
        $this->postJson('/api/display/state', ['mode' => 'black', 'expires_in_minutes' => 0], $this->auth())
            ->assertJson(['expires_at' => null]);

        $this->travel(10)->years();

        $this->getJson('/api/display/state')->assertJson(['mode' => 'black']);
    }

    public function test_auto_restore_zero_config_means_no_default_expiry(): void
    {
        config(['posters.dimming.auto_restore_minutes' => 0]);

        $this->postJson('/api/display/state', ['mode' => 'dim'], $this->auth())->assertJson(['expires_at' => null]);
    }

    public function test_expired_state_is_reported_as_normal(): void
    {
        $this->postJson('/api/display/state', ['mode' => 'black', 'expires_in_minutes' => 30], $this->auth())
            ->assertJson(['mode' => 'black', 'version' => 2]);

        $this->travel(29)->minutes();
        $this->getJson('/api/display/state')->assertJson(['mode' => 'black', 'version' => 2]);

        $this->travel(2)->minutes();
        $this->getJson('/api/display/state')
            ->assertJson(['mode' => 'normal', 'brightness' => 100, 'version' => 3, 'expires_at' => null]);

        // Restored only once.
        $this->getJson('/api/display/state')->assertJson(['version' => 3]);
    }

    public function test_auto_restore_command_resets_expired_state(): void
    {
        $this->postJson('/api/display/state', ['mode' => 'dim', 'expires_in_minutes' => 10], $this->auth());

        $this->artisan('display:auto-restore')->assertSuccessful();
        $this->assertSame('dim', DisplayState::current()->mode);

        $this->travel(11)->minutes();

        $this->artisan('display:auto-restore')->expectsOutputToContain('restored')->assertSuccessful();

        $state = DisplayState::current();
        $this->assertSame('normal', $state->mode);
        $this->assertSame(100, $state->brightness);
        $this->assertNull($state->expires_at);
        $this->assertSame(3, $state->version);
    }

    public function test_auto_restore_is_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('display:auto-restore')
            ->assertSuccessful();
    }
}
