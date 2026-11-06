<?php

use App\Http\Controllers\DisplayStateController;
use App\Http\Controllers\PosterController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

/*
| Public, read-only (LAN). Used by the display page. No throttling: the
| display polls the state endpoint every POSTER_STATE_POLL_MS.
*/
Route::get('display/state', [DisplayStateController::class, 'show'])->name('api.display.state');
Route::get('posters/next', [PosterController::class, 'next'])->name('api.posters.next');
Route::get('status', StatusController::class)->name('api.status');

/*
| Control: requires POSTER_API_TOKEN (Bearer header or ?token=).
*/
Route::middleware('poster.token')->group(function () {
    Route::post('display/state', [DisplayStateController::class, 'update'])->name('api.display.state.update');
    Route::post('display/playback', [DisplayStateController::class, 'playback'])->name('api.display.playback');
    Route::post('sync', SyncController::class)->name('api.sync');
});
