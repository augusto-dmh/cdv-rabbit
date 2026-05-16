<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * laravel/ai Agent for the v2 critic pass.
 *
 * Mirrors ReviewAgent: system prompt and tool schema are injected at call
 * time so the critic prompt + schema can be loaded from frozen config
 * artifacts at boot by ClaudeReviewer.
 *
 * Cache-control is ephemeral on the critic system block as well — the
 * critic prompt is its own cacheable artifact, separate from the v1/v2
 * reviewer prompt cache prefix.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-4-6')]
#[MaxTokens(4096)]
#[Timeout(300)]
class CriticAgent implements Agent, HasProviderOptions
{
    use Promptable;

    private string $systemInstructions = '';

    public function withInstructions(string $instructions): self
    {
        $this->systemInstructions = $instructions;

        return $this;
    }

    public function instructions(): Stringable|string
    {
        return $this->systemInstructions;
    }

    public function providerOptions(Lab|string $provider): array
    {
        if ($provider !== Lab::Anthropic && $provider !== 'anthropic') {
            return [];
        }

        return [
            'cache_control' => ['type' => 'ephemeral'],
            'tool_choice' => ['type' => 'tool', 'name' => 'critic_result'],
            'strict' => true,
        ];
    }
}
