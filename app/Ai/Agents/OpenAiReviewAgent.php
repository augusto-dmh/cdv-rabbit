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
use RuntimeException;
use Stringable;

/**
 * laravel/ai Agent for PR diff review using OpenAI.
 *
 * Uses response_format: json_schema (strict) instead of Anthropic tool_choice.
 * The schema is injected at construction time so the agent class stays stateless
 * with respect to which schema version is loaded.
 */
#[Provider(Lab::OpenAI)]
#[Model('gpt-4o')]
#[MaxTokens(4096)]
#[Timeout(300)]
class OpenAiReviewAgent implements Agent, HasProviderOptions
{
    use Promptable;

    private string $systemInstructions = '';

    /** @var array<string, mixed> */
    private array $toolSchema = [];

    public function withInstructions(string $instructions): self
    {
        $this->systemInstructions = $instructions;

        return $this;
    }

    /** @param array<string, mixed> $schema */
    public function withSchema(array $schema): self
    {
        $this->toolSchema = $schema;

        return $this;
    }

    public function instructions(): Stringable|string
    {
        return $this->systemInstructions;
    }

    public function providerOptions(Lab|string $provider): array
    {
        if ($this->toolSchema === []) {
            throw new RuntimeException('OpenAiReviewAgent: schema must be set before calling providerOptions.');
        }

        return [
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'review_result',
                    'strict' => true,
                    'schema' => $this->toolSchema,
                ],
            ],
        ];
    }
}
