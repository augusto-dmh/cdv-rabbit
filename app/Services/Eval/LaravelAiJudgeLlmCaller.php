<?php

declare(strict_types=1);

namespace App\Services\Eval;

use App\Ai\Agents\OpenAiJudgeAgent;
use App\Ai\Agents\ReviewAgent;
use InvalidArgumentException;
use RuntimeException;

/**
 * Default JudgeLlmCallerInterface implementation backed by the laravel/ai
 * agent classes. The judge prompt expects a JSON object in the response
 * BODY, so the OpenAI cell uses `OpenAiJudgeAgent` (plain-text, no
 * HasStructuredOutput) — ADR 0007 §5 fix. The Anthropic cell uses
 * `ReviewAgent` streamed; the parsing layer extracts JSON from either.
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
        $response = (new OpenAiJudgeAgent)
            ->withInstructions($systemPrompt)
            ->prompt($userMessage);

        return (string) $response;
    }
}
