<?php

declare(strict_types=1);

namespace App\Services\Eval;

use App\Ai\Agents\OpenAiReviewAgent;
use App\Ai\Agents\ReviewAgent;
use InvalidArgumentException;
use RuntimeException;

/**
 * Default JudgeLlmCallerInterface implementation backed by the laravel/ai
 * agent classes already used by ClaudeReviewer and OpenAiReviewer. The judge
 * uses a tiny no-schema prompt; we lean on plain text output and parse it
 * downstream rather than re-using the strict review_result_v1 tool schema.
 *
 * This caller is invoked only by `rabbit:eval` against the live API; the
 * EvalCommand feature test binds JudgeLlmCallerInterface to a fake before
 * dispatching, so this class is not exercised in CI tests.
 */
final class LaravelAiJudgeLlmCaller implements JudgeLlmCallerInterface
{
    public function complete(string $provider, string $systemPrompt, string $userMessage): string
    {
        return match ($provider) {
            'anthropic' => $this->callAnthropic($systemPrompt, $userMessage),
            'openai' => $this->callOpenAi($systemPrompt, $userMessage),
            default => throw new InvalidArgumentException("LaravelAiJudgeLlmCaller: unsupported provider [{$provider}]."),
        };
    }

    private function callAnthropic(string $systemPrompt, string $userMessage): string
    {
        $captured = null;

        $stream = (new ReviewAgent)
            ->withInstructions($systemPrompt)
            ->stream($userMessage)
            ->then(function ($response) use (&$captured): void {
                $captured = (string) $response;
            });

        foreach ($stream as $_) {
            // Drain.
        }

        if ($captured === null) {
            throw new RuntimeException('LaravelAiJudgeLlmCaller: Anthropic judge stream returned no response.');
        }

        return (string) $captured;
    }

    private function callOpenAi(string $systemPrompt, string $userMessage): string
    {
        $response = (new OpenAiReviewAgent)
            ->withInstructions($systemPrompt)
            ->prompt($userMessage);

        return (string) $response;
    }
}
