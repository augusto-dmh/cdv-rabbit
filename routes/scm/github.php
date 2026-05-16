<?php

declare(strict_types=1);

use App\Http\Controllers\Github\WebhookController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::post('/scm/github/webhook', WebhookController::class)
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('github.webhook');
