<?php

namespace App\Services\Llm;

use App\Models\Workspace;

final class LlmDriverFactory
{
    public function make(Workspace $workspace): LlmDriverInterface
    {
        return match ($workspace->llm_provider) {
            'openai' => app(OpenAiReviewer::class),
            default => app(ClaudeReviewer::class),
        };
    }
}
