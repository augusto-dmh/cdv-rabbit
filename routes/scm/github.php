<?php

declare(strict_types=1);

use App\Http\Controllers\Github\WebhookController;
use App\Http\Controllers\Scm\Github\InstallController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::post('/scm/github/webhook', WebhookController::class)
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('github.webhook');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('/scm/github/install/start/{workspace}', [InstallController::class, 'start'])
        ->name('github.install.start');

    Route::get('/scm/github/install/callback', [InstallController::class, 'callback'])
        ->name('github.install.callback');
});
