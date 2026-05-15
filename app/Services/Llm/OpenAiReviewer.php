<?php

namespace App\Services\Llm;

use App\Ai\Agents\OpenAiReviewAgent;
use App\Services\Llm\Dto\ReviewCommentDto;
use App\Services\Llm\Dto\ReviewResultDto;
use App\Services\Llm\Dto\ReviewSummaryDto;
use App\Support\OpenAiErrorClassifier;
use App\Support\OpenAiHeaderBag;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Throwable;

final class OpenAiReviewer implements LlmDriverInterface
{
    private string $systemPrompt;

    /** @var array<string, mixed> */
    private array $toolSchema;

    public function __construct(
        private readonly Container $container,
        private readonly OpenAiErrorClassifier $classifier,
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

            $streamable = (new OpenAiReviewAgent)
                ->withInstructions($systemPrompt)
                ->withSchema($toolSchema)
                ->stream($userMessage)
                ->then(function ($r) use (&$streamedResponse): void {
                    $streamedResponse = $r;
                });

            foreach ($streamable as $event) {
                // Drain events; we only need the final aggregated response.
            }

            $endMs = (int) (microtime(true) * 1000);

            $usage = $streamedResponse->usage;
            $headerBag = $this->container->make(OpenAiHeaderBag::class);

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

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function getToolSchema(): array
    {
        return $this->toolSchema;
    }

    private function buildResult(
        mixed $response,
        mixed $usage,
        OpenAiHeaderBag $headerBag,
        int $durationMs,
    ): ReviewResultDto {
        $parsed = $this->extractParsedOutput($response);

        $summary = ReviewSummaryDto::fromArray($parsed['summary']);
        $comments = array_map(
            fn (array $c) => ReviewCommentDto::fromArray($c),
            $parsed['comments'] ?? [],
        );

        return new ReviewResultDto(
            summary: $summary,
            comments: $comments,
            inputTokens: $usage->promptTokens ?? 0,
            cacheCreationInputTokens: 0,
            cacheReadInputTokens: 0,
            outputTokens: $usage->completionTokens ?? 0,
            requestId: $headerBag->requestId,
            rateLimitTokensRemaining: $headerBag->rateLimitTokensRemaining,
            rateLimitTokensReset: $headerBag->rateLimitTokensReset,
            durationMs: $durationMs,
        );
    }

    private function extractParsedOutput(mixed $response): array
    {
        // response_format: json_schema guarantees the text is valid JSON matching the schema.
        $text = (string) $response;

        if ($text !== '') {
            $decoded = json_decode($text, true);
            if (is_array($decoded) && isset($decoded['summary'])) {
                return $decoded;
            }
        }

        throw new RuntimeException('OpenAiReviewer: response is not valid review JSON.');
    }

    private function loadSystemPrompt(): string
    {
        $path = config_path('cdv-rabbit/prompts/review_v1.txt');

        if (! file_exists($path)) {
            throw new RuntimeException("System prompt not found at {$path}");
        }

        return file_get_contents($path);
    }

    private function loadToolSchema(): array
    {
        $path = config_path('cdv-rabbit/schemas/review_result_v1.json');

        if (! file_exists($path)) {
            throw new RuntimeException("Tool schema not found at {$path}");
        }

        $schema = json_decode(file_get_contents($path), true);

        if (! is_array($schema)) {
            throw new RuntimeException('Tool schema is invalid JSON.');
        }

        return $schema;
    }
}
