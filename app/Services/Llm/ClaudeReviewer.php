<?php

namespace App\Services\Llm;

use App\Ai\Agents\CriticAgent;
use App\Ai\Agents\ReviewAgent;
use App\Services\Llm\Dto\CritiqueResultDto;
use App\Services\Llm\Dto\DraftReviewDto;
use App\Services\Llm\Dto\ReviewCommentDto;
use App\Services\Llm\Dto\ReviewFindingDto;
use App\Services\Llm\Dto\ReviewNitpickDto;
use App\Services\Llm\Dto\ReviewResultDto;
use App\Services\Llm\Dto\ReviewSummaryDto;
use App\Services\Llm\Dto\ReviewSummaryV2Dto;
use App\Support\AnthropicErrorClassifier;
use App\Support\AnthropicHeaderBag;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Throwable;

final class ClaudeReviewer implements LlmDriverInterface
{
    private string $systemPrompt;

    /** @var array<string, mixed> */
    private array $toolSchema;

    private ?string $systemPromptV2 = null;

    /** @var array<string, mixed>|null */
    private ?array $toolSchemaV2 = null;

    private ?string $criticSystemPrompt = null;

    /** @var array<string, mixed>|null */
    private ?array $criticToolSchema = null;

    public function __construct(
        private readonly Container $container,
        private readonly AnthropicErrorClassifier $classifier,
    ) {
        $this->systemPrompt = $this->loadSystemPrompt();
        $this->toolSchema = $this->loadToolSchema();
    }

    public function reviewDiff(
        string $systemPrompt,
        array $toolSchema,
        string $userMessage,
        array $options = [],
    ): ReviewResultDto {
        $startMs = (int) (microtime(true) * 1000);

        try {
            $streamedResponse = null;

            $streamable = (new ReviewAgent)
                ->withInstructions($systemPrompt)
                ->stream($userMessage)
                ->then(function ($r) use (&$streamedResponse): void {
                    $streamedResponse = $r;
                });

            // Consuming the iterator fires then() callbacks and populates $streamedResponse.
            foreach ($streamable as $event) {
                // Drain events; we only need the final aggregated response.
            }

            $endMs = (int) (microtime(true) * 1000);

            $usage = $streamedResponse->usage;
            $headerBag = $this->container->make(AnthropicHeaderBag::class);

            return $this->buildResult($streamedResponse, $usage, $headerBag, $endMs - $startMs);
        } catch (Throwable $e) {
            $decision = $this->classifier->classify($e);

            throw new LlmReviewException(
                message: $e->getMessage(),
                retryDecision: $decision,
                previous: $e,
            );
        }
    }

    public function reviewDiffV2(
        string $systemPrompt,
        array $toolSchema,
        string $userMessage,
        array $options = [],
    ): DraftReviewDto {
        $startMs = (int) (microtime(true) * 1000);

        try {
            $streamedResponse = null;

            $streamable = (new ReviewAgent)
                ->withInstructions($systemPrompt)
                ->stream($userMessage)
                ->then(function ($r) use (&$streamedResponse): void {
                    $streamedResponse = $r;
                });

            foreach ($streamable as $event) {
                // Drain events; aggregated response captured in then().
            }

            $endMs = (int) (microtime(true) * 1000);

            $usage = $streamedResponse->usage;
            $headerBag = $this->container->make(AnthropicHeaderBag::class);

            return $this->buildDraft($streamedResponse, $usage, $headerBag, $endMs - $startMs);
        } catch (Throwable $e) {
            $decision = $this->classifier->classify($e);

            throw new LlmReviewException(
                message: $e->getMessage(),
                retryDecision: $decision,
                previous: $e,
            );
        }
    }

    public function critiqueDraft(
        string $systemPrompt,
        array $toolSchema,
        string $userMessage,
        array $options = [],
    ): CritiqueResultDto {
        $startMs = (int) (microtime(true) * 1000);

        try {
            $streamedResponse = null;

            $streamable = (new CriticAgent)
                ->withInstructions($systemPrompt)
                ->stream($userMessage)
                ->then(function ($r) use (&$streamedResponse): void {
                    $streamedResponse = $r;
                });

            foreach ($streamable as $event) {
                // Drain events; aggregated response captured in then().
            }

            $endMs = (int) (microtime(true) * 1000);

            $usage = $streamedResponse->usage;
            $headerBag = $this->container->make(AnthropicHeaderBag::class);

            return $this->buildCritique($streamedResponse, $usage, $headerBag, $endMs - $startMs);
        } catch (Throwable $e) {
            $decision = $this->classifier->classify($e);

            throw new LlmReviewException(
                message: $e->getMessage(),
                retryDecision: $decision,
                previous: $e,
            );
        }
    }

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function getToolSchema(): array
    {
        return $this->toolSchema;
    }

    public function getReviewSystemPromptForVersion(string $version): string
    {
        if ($version === 'v2') {
            return $this->systemPromptV2 ??= $this->loadFromConfig('cdv-rabbit/prompts/review_v2.txt');
        }

        return $this->systemPrompt;
    }

    public function getReviewToolSchemaForVersion(string $version): array
    {
        if ($version === 'v2') {
            return $this->toolSchemaV2 ??= $this->decodeFromConfig('cdv-rabbit/schemas/review_result_v2.json');
        }

        return $this->toolSchema;
    }

    public function getCriticSystemPrompt(): string
    {
        return $this->criticSystemPrompt ??= $this->loadFromConfig('cdv-rabbit/prompts/critic_v1.txt');
    }

    public function getCriticToolSchema(): array
    {
        return $this->criticToolSchema ??= $this->decodeFromConfig('cdv-rabbit/schemas/critic_result_v1.json');
    }

    private function buildResult(
        mixed $response,
        mixed $usage,
        AnthropicHeaderBag $headerBag,
        int $durationMs,
    ): ReviewResultDto {
        // Extract tool call input from the streamed response
        $toolInput = $this->extractToolInput($response, 'review_result');

        $summary = ReviewSummaryDto::fromArray($toolInput['summary']);
        $comments = array_map(
            fn (array $c) => ReviewCommentDto::fromArray($c),
            $toolInput['comments'] ?? [],
        );

        return new ReviewResultDto(
            summary: $summary,
            comments: $comments,
            inputTokens: $usage->promptTokens ?? 0,
            cacheCreationInputTokens: $usage->cacheWriteInputTokens ?? 0,
            cacheReadInputTokens: $usage->cacheReadInputTokens ?? 0,
            outputTokens: $usage->completionTokens ?? 0,
            requestId: $headerBag->requestId,
            rateLimitTokensRemaining: $headerBag->rateLimitTokensRemaining,
            rateLimitTokensReset: $headerBag->rateLimitTokensReset,
            durationMs: $durationMs,
        );
    }

    private function buildDraft(
        mixed $response,
        mixed $usage,
        AnthropicHeaderBag $headerBag,
        int $durationMs,
    ): DraftReviewDto {
        $toolInput = $this->extractToolInput($response, 'review_result_v2');

        $summary = ReviewSummaryV2Dto::fromArray($toolInput['summary']);
        $findings = array_values(array_map(
            fn (array $f) => ReviewFindingDto::fromArray($f),
            $toolInput['findings'] ?? [],
        ));
        $nitpicks = array_values(array_map(
            fn (array $n) => ReviewNitpickDto::fromArray($n),
            $toolInput['nitpicks'] ?? [],
        ));

        return new DraftReviewDto(
            summary: $summary,
            findings: $findings,
            nitpicks: $nitpicks,
            inputTokens: $usage->promptTokens ?? 0,
            cacheCreationInputTokens: $usage->cacheWriteInputTokens ?? 0,
            cacheReadInputTokens: $usage->cacheReadInputTokens ?? 0,
            outputTokens: $usage->completionTokens ?? 0,
            requestId: $headerBag->requestId,
            rateLimitTokensRemaining: $headerBag->rateLimitTokensRemaining,
            rateLimitTokensReset: $headerBag->rateLimitTokensReset,
            durationMs: $durationMs,
        );
    }

    private function buildCritique(
        mixed $response,
        mixed $usage,
        AnthropicHeaderBag $headerBag,
        int $durationMs,
    ): CritiqueResultDto {
        $toolInput = $this->extractToolInput($response, 'critic_result');

        $decisions = [];
        foreach (($toolInput['decisions'] ?? []) as $d) {
            $decisions[] = [
                'finding_index' => (int) ($d['finding_index'] ?? 0),
                'verdict' => (string) ($d['verdict'] ?? 'reject'),
                'reason' => (string) ($d['reason'] ?? ''),
            ];
        }

        return new CritiqueResultDto(
            decisions: $decisions,
            inputTokens: $usage->promptTokens ?? 0,
            cacheCreationInputTokens: $usage->cacheWriteInputTokens ?? 0,
            cacheReadInputTokens: $usage->cacheReadInputTokens ?? 0,
            outputTokens: $usage->completionTokens ?? 0,
            requestId: $headerBag->requestId,
            rateLimitTokensRemaining: $headerBag->rateLimitTokensRemaining,
            rateLimitTokensReset: $headerBag->rateLimitTokensReset,
            durationMs: $durationMs,
        );
    }

    private function extractToolInput(mixed $response, string $toolName): array
    {
        // When the agent uses a tool, the tool call input is in toolCalls
        $toolCalls = $response->toolCalls ?? collect();

        foreach ($toolCalls as $toolCall) {
            if ($toolCall->name === $toolName) {
                $input = $toolCall->arguments ?? [];

                return is_string($input) ? json_decode($input, true) : $input;
            }
        }

        // Fallback: try to decode the text response as JSON (structured output path)
        $text = (string) $response;
        if ($text !== '') {
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new RuntimeException("ClaudeReviewer: no {$toolName} tool call in response.");
    }

    private function loadSystemPrompt(): string
    {
        return $this->loadFromConfig('cdv-rabbit/prompts/review_v1.txt');
    }

    private function loadToolSchema(): array
    {
        return $this->decodeFromConfig('cdv-rabbit/schemas/review_result_v1.json');
    }

    private function loadFromConfig(string $relativePath): string
    {
        $path = config_path($relativePath);

        if (! file_exists($path)) {
            throw new RuntimeException("Config artifact not found at {$path}");
        }

        return (string) file_get_contents($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeFromConfig(string $relativePath): array
    {
        $raw = $this->loadFromConfig($relativePath);
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Config artifact at {$relativePath} is invalid JSON.");
        }

        return $decoded;
    }
}
