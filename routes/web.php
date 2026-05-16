<?php

use App\Http\Controllers\Admin\KillSwitchController;
use App\Http\Controllers\Health\HealthController;
use App\Http\Controllers\Reviews\ReviewController;
use App\Http\Controllers\Scm\Github\InstallController as GithubInstallController;
use App\Http\Controllers\Workspaces\ConnectController;
use App\Http\Controllers\Workspaces\RepositoryController;
use App\Http\Controllers\Workspaces\WorkspaceController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::get('/up', HealthController::class)->name('health');

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

        Route::get('connect-github', [GithubInstallController::class, 'show'])->name('workspaces.connect-github.edit');

        Route::get('repositories', [RepositoryController::class, 'index'])->name('workspaces.repositories.index');
        Route::post('repositories/sync', [RepositoryController::class, 'sync'])->name('workspaces.repositories.sync');
        Route::patch('repositories/{repository}', [RepositoryController::class, 'update'])->name('workspaces.repositories.update');

        Route::get('reviews', [ReviewController::class, 'index'])->name('workspaces.reviews.index');
        Route::get('reviews/{review}', [ReviewController::class, 'show'])->name('workspaces.reviews.show');

        Route::get('admin/kill-switch', [KillSwitchController::class, 'edit'])->name('workspaces.admin.kill-switch.edit');
        Route::put('admin/kill-switch', [KillSwitchController::class, 'update'])->name('workspaces.admin.kill-switch.update');
    });

require __DIR__.'/settings.php';
