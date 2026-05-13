<?php

use App\Http\Controllers\Workspaces\ConnectController;
use App\Http\Controllers\Workspaces\WorkspaceController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::resource('workspaces', WorkspaceController::class)->only(['index', 'show', 'store', 'update']);
});

Route::middleware(['auth', 'verified', 'workspace.member'])
    ->prefix('workspaces/{workspace:slug}')
    ->group(function () {
        Route::get('connect', [ConnectController::class, 'edit'])->name('workspaces.connect.edit');
        Route::put('connect', [ConnectController::class, 'update'])->name('workspaces.connect.update');
        Route::delete('connect', [ConnectController::class, 'destroy'])->name('workspaces.connect.destroy');
    });

require __DIR__.'/settings.php';
