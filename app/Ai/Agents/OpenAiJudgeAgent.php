<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Plain-text agent for the eval `LlmJudge` OpenAI cell.
 *
 * Distinct from OpenAiReviewAgent: the judge prompt expects a JSON object in
 * the response BODY (not a structured-output schema), so this agent does NOT
 * implement HasStructuredOutput — preventing laravel/ai from calling
 * `schema()` and tripping on a missing tool schema. The caller parses the
 * JSON out of the text response itself.
 */
#[Provider(Lab::OpenAI)]
#[Model('gpt-4o')]
#[MaxTokens(2048)]
#[Timeout(120)]
class OpenAiJudgeAgent implements Agent
{
    use Promptable;

    public string $systemInstructions = '';

    public function withInstructions(string $instructions): self
    {
        $this->systemInstructions = $instructions;

        return $this;
    }

    public function instructions(): Stringable|string
    {
        return $this->systemInstructions;
    }
}
