<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the control API.
 *
 * - No POSTER_API_TOKEN configured  -> 503 (control endpoints disabled).
 * - Token missing or wrong          -> 401.
 *
 * The token is read from "Authorization: Bearer <token>" or, as a fallback for
 * clients where headers are awkward, the "?token=" query string parameter.
 */
class RequirePosterToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('posters.api_token', '');

        if ($expected === '') {
            return response()->json([
                'message' => 'Control API is disabled: set POSTER_API_TOKEN in .env (php artisan posters:token).',
            ], 503);
        }

        $given = $request->bearerToken();

        if ($given === null || $given === '') {
            $query = $request->query('token');
            $given = is_string($query) ? $query : '';
        }

        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthenticated.'], 401, [
                'WWW-Authenticate' => 'Bearer',
            ]);
        }

        return $next($request);
    }
}
