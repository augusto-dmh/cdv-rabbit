<?php

namespace App\Providers;

use App\Concerns\WorkspaceContext;
use App\Models\Repository;
use App\Models\Workspace;
use App\Queue\RedactingFailedJobProvider;
use App\Services\Llm\ClaudeReviewer;
use App\Services\Llm\LlmDriverFactory;
use App\Services\Llm\LlmDriverInterface;
use App\Services\Review\CostReservation;
use App\Services\Review\CostReservationInterface;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WorkspaceContext::class);
        $this->app->bind(CostReservationInterface::class, CostReservation::class);
        $this->app->bind(LlmDriverInterface::class, ClaudeReviewer::class);
        $this->app->singleton(LlmDriverFactory::class);
    }

    // QueueServiceProvider is deferred — it registers queue.failer lazily. Using extend()
    // wraps the resolved value after the deferred provider registers and resolves it.
    protected function rebindFailedJobProvider(): void
    {
        $this->app->extend('queue.failer', function ($failer, $app) {
            if ($failer instanceof RedactingFailedJobProvider) {
                return $failer;
            }

            $database = new DatabaseUuidFailedJobProvider(
                $app['db'],
                $app['config']['database.default'],
                'failed_jobs',
            );

            return new RedactingFailedJobProvider($database, $app['events']);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->rebindFailedJobProvider();
        $this->configureDefaults();
        $this->configureRouteBindings();
    }

    protected function configureRouteBindings(): void
    {
        Route::bind('repository', function (string $value): Repository {
            $repository = Repository::withoutWorkspaceScope()->findOrFail($value);
            app(WorkspaceContext::class)->bind($repository->workspace_id);

            return $repository;
        });

        Route::bind('workspace', function (string $value): Workspace {
            return Workspace::withoutGlobalScope('workspace')->where('slug', $value)->firstOrFail();
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
