<?php

namespace App\Http\Controllers;

use App\Models\SyncRun;
use App\Posters\Exceptions\SyncAlreadyRunningException;
use App\Posters\PosterSourceManager;
use App\Posters\PosterSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * POST /api/sync[?source=emby]  (bearer-token protected by the route middleware)
 *
 * Runs the sync synchronously and returns the resulting SyncRun records.
 */
class SyncController extends Controller
{
    public function __invoke(Request $request, PosterSourceManager $manager, PosterSyncService $sync): JsonResponse
    {
        $request->validate([
            'source' => ['sometimes', 'nullable', 'string', 'max:32'],
            'force_images' => ['sometimes', 'boolean'],
        ]);

        try {
            $sources = $request->filled('source')
                ? [$request->string('source')->toString() => $manager->source($request->string('source')->toString())]
                : $manager->enabled();
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($sources === []) {
            return response()->json(['message' => 'No poster sources are enabled (POSTER_SOURCES).'], 422);
        }

        foreach (array_keys($sources) as $key) {
            if ($sync->isRunning($key)) {
                return $this->alreadyRunning($key);
            }
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        $runs = [];

        foreach ($sources as $key => $source) {
            try {
                $runs[] = $sync->sync($source, $request->boolean('force_images'));
            } catch (SyncAlreadyRunningException $e) {
                if ($runs === []) {
                    return $this->alreadyRunning($e->source);
                }
                // A later source got locked between the check and the run; report what we did.
            }
        }

        return response()->json([
            'ok' => collect($runs)->every(fn (SyncRun $run) => $run->succeeded()),
            'runs' => $runs,
        ]);
    }

    private function alreadyRunning(string $key): JsonResponse
    {
        return response()->json([
            'message' => "A sync for [{$key}] is already running.",
            'source' => $key,
            'run' => SyncRun::query()->where('source', $key)->where('status', SyncRun::RUNNING)->latest('id')->first(),
        ], 409);
    }
}
