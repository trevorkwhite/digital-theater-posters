<?php

namespace App\Http\Controllers;

use App\Models\Poster;
use App\Posters\PosterPicker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PosterController extends Controller
{
    /** GET /api/posters/next?exclude[]=ID */
    public function next(Request $request, PosterPicker $picker): JsonResponse
    {
        $validated = $request->validate([
            'exclude' => ['sometimes', 'array', 'max:500'],
            'exclude.*' => ['integer', 'min:1'],
        ]);

        $poster = $picker->next($validated['exclude'] ?? []);

        if ($poster === null) {
            return response()
                ->json(['message' => 'No posters cached yet. Run php artisan posters:sync.'], 404)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'id' => $poster->id,
            'title' => $poster->title,
            'year' => $poster->year,
            'official_rating' => $poster->official_rating,
            'runtime_minutes' => $poster->runtime_minutes,
            'image_url' => $poster->image_url,
        ])->header('Cache-Control', 'no-store');
    }

    /** GET /posters/{poster}/image (route name: posters.image) */
    public function image(Request $request, Poster $poster): SymfonyResponse
    {
        $disk = Storage::disk(config('posters.images.disk', 'posters'));
        $path = $poster->image_path;

        if (empty($path) || ! $disk->exists($path)) {
            abort(404, 'Poster image not cached.');
        }

        $etag = $this->etag($poster);

        $notModified = new Response;
        $this->applyCacheHeaders($notModified, $etag);

        if ($notModified->isNotModified($request)) {
            return $notModified; // 304
        }

        $response = $disk->response($path, basename($path), [
            'Content-Type' => $poster->image_mime ?: ($disk->mimeType($path) ?: 'application/octet-stream'),
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');

        $this->applyCacheHeaders($response, $etag);

        return $response;
    }

    private function etag(Poster $poster): string
    {
        $tag = (string) ($poster->image_tag ?: sha1((string) $poster->image_path));

        // ETags must be printable ASCII without quotes.
        return preg_match('/^[\x21\x23-\x7E]+$/', $tag) ? $tag : sha1($tag);
    }

    private function applyCacheHeaders(SymfonyResponse $response, string $etag): void
    {
        $response->setEtag($etag);
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setImmutable();
    }
}
