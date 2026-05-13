<?php

use App\Http\Controllers\Bitbucket\WebhookController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::post('/bb/webhook/{repository}/{webhookToken}', WebhookController::class)
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('bitbucket.webhook');
