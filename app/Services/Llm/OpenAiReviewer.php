<?php

namespace App\Services\Llm;

use App\Ai\Agents\OpenAiCriticAgent;
use App\Ai\Agents\OpenAiReviewAgent;
use App\Services\Llm\Dto\CritiqueResultDto;
use App\Services\Llm\Dto\DraftReviewDto;
use App\Services\Llm\Dto\ReviewCommentDto;
use App\Services\Llm\Dto\ReviewFindingDto;
use App\Services\Llm\Dto\ReviewNitpickDto;
use App\Services\Llm\Dto\ReviewResultDto;
use App\Services\Llm\Dto\ReviewSummaryDto;
use App\Services\Llm\Dto\ReviewSummaryV2Dto;
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

    private ?string $systemPromptV2 = null;

    /** @var array<string, mixed>|null */
    private ?array $toolSchemaV2 = null;

    private ?string $criticSystemPrompt = null;

    /** @var array<string, mixed>|null */
    private ?array $criticToolSchema = null;

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
            // laravel/ai rejects `stream()` + HasStructuredOutput on the OpenAI
            // Responses API ("Streaming structured output is not currently supported"),
            // so we use the non-streaming `prompt()` and aggregate the AgentResponse.
            $response = (new OpenAiReviewAgent)
                ->withInstructions($systemPrompt)
                ->withSchema($toolSchema)
                ->prompt($userMessage);

            $endMs = (int) (microtime(true) * 1000);

            $headerBag = $this->container->make(OpenAiHeaderBag::class);

            return $this->buildResult($response, $response->usage, $headerBag, $endMs - $startMs);
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
            $response = (new OpenAiReviewAgent)
                ->withInstructions($systemPrompt)
                ->withSchema($toolSchema)
                ->prompt($userMessage);

            $endMs = (int) (microtime(true) * 1000);

            $headerBag = $this->container->make(OpenAiHeaderBag::class);

            return $this->buildDraft($response, $response->usage, $headerBag, $endMs - $startMs);
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
            $response = (new OpenAiCriticAgent)
                ->withInstructions($systemPrompt)
                ->withSchema($toolSchema)
                ->prompt($userMessage);

            $endMs = (int) (microtime(true) * 1000);

            $headerBag = $this->container->make(OpenAiHeaderBag::class);

            return $this->buildCritique($response, $response->usage, $headerBag, $endMs - $startMs);
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
        OpenAiHeaderBag $headerBag,
        int $durationMs,
    ): ReviewResultDto {
        $parsed = $this->extractParsedOutput($response, requiresKey: 'summary');

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

    private function buildDraft(
        mixed $response,
        mixed $usage,
        OpenAiHeaderBag $headerBag,
        int $durationMs,
    ): DraftReviewDto {
        $parsed = $this->extractParsedOutput($response, requiresKey: 'summary');

        $summary = ReviewSummaryV2Dto::fromArray($parsed['summary']);
        $findings = array_values(array_map(
            fn (array $f) => ReviewFindingDto::fromArray($f),
            $parsed['findings'] ?? [],
        ));
        $nitpicks = array_values(array_map(
            fn (array $n) => ReviewNitpickDto::fromArray($n),
            $parsed['nitpicks'] ?? [],
        ));

        return new DraftReviewDto(
            summary: $summary,
            findings: $findings,
            nitpicks: $nitpicks,
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

    private function buildCritique(
        mixed $response,
        mixed $usage,
        OpenAiHeaderBag $headerBag,
        int $durationMs,
    ): CritiqueResultDto {
        $parsed = $this->extractParsedOutput($response, requiresKey: 'decisions');

        $decisions = [];
        foreach (($parsed['decisions'] ?? []) as $d) {
            $decisions[] = [
                'finding_index' => (int) ($d['finding_index'] ?? 0),
                'verdict' => (string) ($d['verdict'] ?? 'reject'),
                'reason' => (string) ($d['reason'] ?? ''),
            ];
        }

        return new CritiqueResultDto(
            decisions: $decisions,
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

    private function extractParsedOutput(mixed $response, string $requiresKey): array
    {
        // response_format: json_schema guarantees the text is valid JSON matching the schema.
        $text = (string) $response;

        if ($text !== '') {
            $decoded = json_decode($text, true);
            if (is_array($decoded) && array_key_exists($requiresKey, $decoded)) {
                return $decoded;
            }
        }

        throw new RuntimeException("OpenAiReviewer: response is not valid JSON containing '{$requiresKey}'.");
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
