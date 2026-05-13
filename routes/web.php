<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::middleware(['auth', 'verified', 'workspace.member'])
    ->prefix('workspaces/{workspace:slug}')
    ->group(function () {
        // W4 routes attach here
    });

require __DIR__.'/settings.php';
