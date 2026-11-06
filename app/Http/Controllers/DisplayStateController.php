<?php

namespace App\Http\Controllers;

use App\Display\DisplayStateService;
use App\Http\Requests\DisplayPlaybackRequest;
use App\Http\Requests\UpdateDisplayStateRequest;
use Illuminate\Http\JsonResponse;

class DisplayStateController extends Controller
{
    public function __construct(private readonly DisplayStateService $display) {}

    /** GET /api/display/state (public, polled by the display). */
    public function show(): JsonResponse
    {
        return $this->json($this->display->payload());
    }

    /** POST /api/display/state {mode, brightness?, fade_ms?, reason?, expires_in_minutes?} */
    public function update(UpdateDisplayStateRequest $request): JsonResponse
    {
        $data = $request->validated();

        $state = $this->display->set(
            mode: $data['mode'],
            brightness: isset($data['brightness']) ? (int) $data['brightness'] : null,
            fadeMs: isset($data['fade_ms']) ? (int) $data['fade_ms'] : null,
            reason: $data['reason'] ?? 'api',
            expiresInMinutes: isset($data['expires_in_minutes']) ? (int) $data['expires_in_minutes'] : null,
        );

        return $this->json($this->display->payload($state));
    }

    /** POST /api/display/playback {event: play|pause|resume|stop} */
    public function playback(DisplayPlaybackRequest $request): JsonResponse
    {
        $data = $request->validated();

        $state = $this->display->handlePlayback($data['event'], $data['reason'] ?? null);

        return $this->json($this->display->payload($state) + ['event' => $data['event']]);
    }

    private function json(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'no-store');
    }
}
