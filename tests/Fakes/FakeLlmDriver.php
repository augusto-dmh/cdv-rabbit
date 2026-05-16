<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Services\Llm\Dto\CritiqueResultDto;
use App\Services\Llm\Dto\DraftReviewDto;
use App\Services\Llm\Dto\ReviewResultDto;
use App\Services\Llm\LlmDriverInterface;
use Closure;
use RuntimeException;

/**
 * Reusable LlmDriverInterface fake for feature tests.
 *
 * Anonymous classes implementing the interface in many test files were a
 * maintenance burden once the interface grew with the v2 methods, so this
 * concrete fake centralizes the v1/v2 stubbing surface. Tests can pass a
 * reviewDiff closure (back-compat) and, when exercising the v2 pipeline,
 * also a reviewDiffV2 and critiqueDraft closure.
 */
class FakeLlmDriver implements LlmDriverInterface
{
    /**
     * @param  Closure(string, array, string): ReviewResultDto|null  $reviewDiff
     * @param  Closure(string, array, string): DraftReviewDto|null  $reviewDiffV2
     * @param  Closure(string, array, string): CritiqueResultDto|null  $critiqueDraft
     */
    public function __construct(
        public ?Closure $reviewDiff = null,
        public ?Closure $reviewDiffV2 = null,
        public ?Closure $critiqueDraft = null,
        public string $systemPromptV1 = '',
        public string $systemPromptV2 = '',
        public array $toolSchemaV1 = [],
        public array $toolSchemaV2 = [],
        public string $criticSystemPrompt = '',
        public array $criticToolSchema = [],
    ) {}

    public function reviewDiff(string $systemPrompt, array $toolSchema, string $userMessage, array $options = []): ReviewResultDto
    {
        if ($this->reviewDiff === null) {
            throw new RuntimeException('FakeLlmDriver::reviewDiff not stubbed.');
        }

        return ($this->reviewDiff)($systemPrompt, $toolSchema, $userMessage);
    }

    public function reviewDiffV2(string $systemPrompt, array $toolSchema, string $userMessage, array $options = []): DraftReviewDto
    {
        if ($this->reviewDiffV2 === null) {
            throw new RuntimeException('FakeLlmDriver::reviewDiffV2 not stubbed.');
        }

        return ($this->reviewDiffV2)($systemPrompt, $toolSchema, $userMessage);
    }

    public function critiqueDraft(string $systemPrompt, array $toolSchema, string $userMessage, array $options = []): CritiqueResultDto
    {
        if ($this->critiqueDraft === null) {
            throw new RuntimeException('FakeLlmDriver::critiqueDraft not stubbed.');
        }

        return ($this->critiqueDraft)($systemPrompt, $toolSchema, $userMessage);
    }

    public function getSystemPrompt(): string
    {
        return $this->systemPromptV1;
    }

    public function getToolSchema(): array
    {
        return $this->toolSchemaV1;
    }

    public function getReviewSystemPromptForVersion(string $version): string
    {
        return $version === 'v2' ? $this->systemPromptV2 : $this->systemPromptV1;
    }

    public function getReviewToolSchemaForVersion(string $version): array
    {
        return $version === 'v2' ? $this->toolSchemaV2 : $this->toolSchemaV1;
    }

    public function getCriticSystemPrompt(): string
    {
        return $this->criticSystemPrompt;
    }

    public function getCriticToolSchema(): array
    {
        return $this->criticToolSchema;
    }
}
