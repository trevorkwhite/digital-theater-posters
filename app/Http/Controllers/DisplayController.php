<?php

namespace App\Http\Controllers;

use App\Display\DisplayStateService;
use Illuminate\Contracts\View\View;
use Throwable;

/** GET / : the full-screen poster display. */
class DisplayController extends Controller
{
    public function __invoke(DisplayStateService $display): View
    {
        $settings = config('posters.display');

        // The page must render even if the database is down; the JS polls later.
        try {
            $initialState = $display->payload();
        } catch (Throwable) {
            $initialState = null;
        }

        $appVersion = $display->appVersion();

        $config = [
            'rotate_seconds' => max(3, (int) ($settings['rotate_seconds'] ?? 30)),
            'transition' => in_array($settings['transition'] ?? null, ['crossfade', 'fade', 'cut'], true) ? $settings['transition'] : 'crossfade',
            'transition_ms' => max(0, (int) ($settings['transition_ms'] ?? 1500)),
            'state_poll_ms' => max(250, (int) ($settings['state_poll_ms'] ?? 1000)),
            'fit' => in_array($settings['fit'] ?? null, ['contain', 'cover'], true) ? $settings['fit'] : 'contain',
            'background' => $this->safeColor($settings['background'] ?? '#000000'),
            'show_title' => (bool) ($settings['show_title'] ?? false),
            'max_uptime_hours' => 24,
            'app_version' => $appVersion,
            'initial_state' => $initialState,
            'endpoints' => [
                'next' => url('/api/posters/next'),
                'state' => url('/api/display/state'),
                'health' => url('/up'),
            ],
        ];

        return view('display', [
            'config' => $config,
            'appVersion' => $appVersion,
        ]);
    }

    /** Only allow plain CSS colours (it is injected into a style attribute). */
    private function safeColor(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^(#[0-9a-fA-F]{3,8}|[a-zA-Z]{3,20}|rgba?\([0-9.,\s%]+\))$/', $value) ? $value : '#000000';
    }
}
