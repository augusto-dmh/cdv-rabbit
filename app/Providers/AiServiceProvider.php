<?php

namespace App\Providers;

use App\Support\AnthropicHeaderBag;
use App\Support\AnthropicTransportMiddleware;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AnthropicHeaderBag::class, fn () => new AnthropicHeaderBag);
    }

    public function boot(): void
    {
        $middleware = new AnthropicTransportMiddleware($this->app);

        Http::globalMiddleware(
            fn (callable $handler) => $middleware($handler)
        );
    }
}
