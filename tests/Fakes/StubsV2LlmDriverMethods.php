<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Services\Llm\Dto\CritiqueResultDto;
use App\Services\Llm\Dto\DraftReviewDto;
use RuntimeException;

/**
 * Trait that provides default v2 LlmDriverInterface method stubs for the
 * existing v1-era anonymous-class test fakes. Each fake only overrode
 * reviewDiff(); after W7-T4 the interface grew six more methods. Pulling
 * this trait into the anonymous class is the smallest diff that keeps the
 * v1 tests valid without rewriting their fixtures.
 */
trait StubsV2LlmDriverMethods
{
    public function reviewDiffV2(string $systemPrompt, array $toolSchema, string $userMessage, array $options = []): DraftReviewDto
    {
        throw new RuntimeException(static::class.'::reviewDiffV2 not implemented in v1-era test fake.');
    }

    public function critiqueDraft(string $systemPrompt, array $toolSchema, string $userMessage, array $options = []): CritiqueResultDto
    {
        throw new RuntimeException(static::class.'::critiqueDraft not implemented in v1-era test fake.');
    }

    public function getReviewSystemPromptForVersion(string $version): string
    {
        return '';
    }

    public function getReviewToolSchemaForVersion(string $version): array
    {
        return [];
    }

    public function getCriticSystemPrompt(): string
    {
        return '';
    }

    public function getCriticToolSchema(): array
    {
        return [];
    }
}
