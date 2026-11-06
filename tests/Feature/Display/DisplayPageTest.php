<?php

namespace Tests\Feature\Display;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisplayPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_page_renders_with_injected_config(): void
    {
        config(['posters.display.transition' => 'fade', 'posters.display.rotate_seconds' => 45]);

        $this->get('/')
            ->assertOk()
            ->assertSee('window.DTP_CONFIG', false)
            ->assertSee('display/display.js', false)
            ->assertSee('display/display.css', false)
            ->assertSee('"transition":"fade"', false)
            ->assertSee('"rotate_seconds":45', false);
    }

    public function test_invalid_display_options_fall_back_to_safe_values(): void
    {
        config([
            'posters.display.transition' => 'spin',
            'posters.display.fit' => 'stretch',
            'posters.display.background' => 'red;}</style><script>alert(1)</script>',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('"transition":"crossfade"', false)
            ->assertSee('"fit":"contain"', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_status_endpoint_reports_display_state(): void
    {
        $this->getJson('/api/status')
            ->assertOk()
            ->assertJsonStructure(['app' => ['name', 'version'], 'posters', 'sources', 'display' => ['mode', 'version'], 'control_api_enabled'])
            ->assertJsonPath('display.mode', 'normal');
    }
}
