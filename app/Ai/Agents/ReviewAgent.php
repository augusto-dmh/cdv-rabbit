<?php

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
 * laravel/ai Agent for PR diff review.
 *
 * System prompt and tool schema are injected at call time (not baked into
 * the class) so they can be loaded from frozen config artifacts at boot
 * by ClaudeReviewer and passed in without agent class changes.
 *
 * providerOptions() attaches:
 * - cache_control (§3.0.2): ephemeral caching on the last system block
 * - tool_choice + strict (§3.0.3): forced single tool call via escape hatch
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-4-6')]
#[MaxTokens(4096)]
#[Timeout(300)]
class ReviewAgent implements Agent, HasProviderOptions
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

    /**
     * Provider-specific options — the §3.0.10 escape hatch for contracts
     * the SDK doesn't expose with first-class typed APIs.
     */
    public function providerOptions(Lab|string $provider): array
    {
        if ($provider !== Lab::Anthropic && $provider !== 'anthropic') {
            return [];
        }

        return [
            'cache_control' => ['type' => 'ephemeral'],
            'tool_choice' => ['type' => 'tool', 'name' => 'review_result'],
            'strict' => true,
        ];
    }
}
