<?php

namespace App\Http\Controllers;

use App\Display\DisplayStateService;
use App\Models\Poster;
use App\Models\SyncRun;
use Illuminate\Http\JsonResponse;
use Throwable;

/** GET /api/status : poster counts, last sync per source, display state. */
class StatusController extends Controller
{
    public function __invoke(DisplayStateService $display): JsonResponse
    {
        return response()->json([
            'app' => [
                'name' => config('app.name'),
                'version' => $display->appVersion(),
                'time' => now()->toIso8601String(),
            ],
            'posters' => $this->posters(),
            'sources' => $this->sources(),
            'display' => $this->safely(fn () => $display->payload()),
            'control_api_enabled' => (string) config('posters.api_token', '') !== '',
        ])->header('Cache-Control', 'no-store');
    }

    private function posters(): ?array
    {
        if (! class_exists(Poster::class)) {
            return null;
        }

        return $this->safely(function () {
            $bySource = Poster::query()
                ->selectRaw('source, COUNT(*) as total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active')
                ->groupBy('source')
                ->get()
                ->mapWithKeys(fn ($row) => [$row->source => [
                    'total' => (int) $row->total,
                    'active' => (int) $row->active,
                ]])
                ->all();

            $total = array_sum(array_column($bySource, 'total'));
            $active = array_sum(array_column($bySource, 'active'));

            return [
                'total' => $total,
                'active' => $active,
                'inactive' => $total - $active,
                'with_image' => Poster::query()->where('is_active', true)->whereNotNull('image_path')->count(),
                'by_source' => $bySource,
            ];
        });
    }

    private function sources(): array
    {
        $keys = array_values(array_unique(array_merge(
            (array) config('posters.enabled_sources', []),
            array_keys((array) config('posters.sources', [])),
        )));

        $enabled = (array) config('posters.enabled_sources', []);
        $out = [];

        foreach ($keys as $key) {
            $out[$key] = [
                'enabled' => in_array($key, $enabled, true),
                'name' => config("posters.sources.{$key}.name", $key),
                'last_sync' => $this->lastSync($key),
                'last_success_at' => $this->lastSuccessAt($key),
            ];
        }

        return $out;
    }

    private function lastSync(string $source): ?array
    {
        if (! class_exists(SyncRun::class)) {
            return null;
        }

        return $this->safely(function () use ($source) {
            $run = SyncRun::query()->where('source', $source)->orderByDesc('id')->first();

            if (! $run) {
                return null;
            }

            return [
                'id' => $run->id,
                'status' => $run->status,
                'items_seen' => (int) $run->items_seen,
                'items_added' => (int) $run->items_added,
                'items_updated' => (int) $run->items_updated,
                'images_downloaded' => (int) $run->images_downloaded,
                'items_deactivated' => (int) $run->items_deactivated,
                'error' => $run->error,
                'started_at' => $this->iso($run->started_at),
                'finished_at' => $this->iso($run->finished_at),
            ];
        });
    }

    private function lastSuccessAt(string $source): ?string
    {
        if (! class_exists(SyncRun::class)) {
            return null;
        }

        return $this->safely(function () use ($source) {
            $run = SyncRun::query()->where('source', $source)->where('status', 'succeeded')->orderByDesc('id')->first();

            return $run ? $this->iso($run->finished_at ?? $run->started_at) : null;
        });
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toIso8601String();
        } catch (Throwable) {
            return is_string($value) ? $value : null;
        }
    }

    /** Status must never 500 just because a table is missing or the DB is down. */
    private function safely(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'unavailable'];
        }
    }
}
