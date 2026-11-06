<?php

use App\Http\Controllers\DisplayController;
use App\Http\Controllers\PosterController;
use Illuminate\Support\Facades\Route;

// The full-screen poster display (opened by Edge in kiosk mode).
Route::get('/', DisplayController::class)->name('display');

// Cached poster image from local disk (PosterController is owned by the cache layer).
// No session/cookie middleware, so Edge can cache images cleanly for offline fallback.
Route::get('/posters/{poster}/image', [PosterController::class, 'image'])
    ->name('posters.image')
    ->withoutMiddleware([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
    ]);
