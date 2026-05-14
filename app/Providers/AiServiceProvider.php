<?php

namespace App\Providers;

use App\Services\Llm\ClaudeReviewer;
use App\Services\Llm\LlmDriverInterface;
use App\Support\AnthropicErrorClassifier;
use App\Support\AnthropicHeaderBag;
use App\Support\AnthropicTransportMiddleware;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AnthropicHeaderBag::class, fn () => new AnthropicHeaderBag);

        $this->app->singleton(LlmDriverInterface::class, function ($app) {
            return new ClaudeReviewer(
                container: $app,
                classifier: $app->make(AnthropicErrorClassifier::class),
            );
        });
    }

    public function boot(): void
    {
        $middleware = new AnthropicTransportMiddleware($this->app);

        Http::globalMiddleware(
            fn (callable $handler) => $middleware($handler)
        );
    }
}
