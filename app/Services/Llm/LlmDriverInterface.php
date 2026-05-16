<?php

namespace App\Services\Llm;

use App\Services\Llm\Dto\CritiqueResultDto;
use App\Services\Llm\Dto\DraftReviewDto;
use App\Services\Llm\Dto\ReviewResultDto;

interface LlmDriverInterface
{
    public function reviewDiff(
        string $systemPrompt,
        array $toolSchema,
        string $userMessage,
        array $options = [],
    ): ReviewResultDto;

    /**
     * v2 draft pass — same call shape as reviewDiff() but emits the v2 schema
     * (Walkthrough + tiered Findings + collapsed Nitpicks). Sibling to
     * reviewDiff() so the v1 method signature stays byte-stable.
     *
     * @param  array<string, mixed>  $toolSchema  the v2 schema array (review_result_v2.json)
     */
    public function reviewDiffV2(
        string $systemPrompt,
        array $toolSchema,
        string $userMessage,
        array $options = [],
    ): DraftReviewDto;

    /**
     * v2 critique pass — verdicts each Finding in the supplied draft as
     * approve|reject. MUST use the same provider as the call that produced
     * $draft (cross-provider critiquing is rejected per ADR 0005).
     *
     * @param  array<string, mixed>  $toolSchema  the critic schema (critic_result_v1.json)
     */
    public function critiqueDraft(
        string $systemPrompt,
        array $toolSchema,
        string $userMessage,
        array $options = [],
    ): CritiqueResultDto;

    public function getSystemPrompt(): string;

    /** @return array<string, mixed> */
    public function getToolSchema(): array;

    /**
     * Return the v2 reviewer system prompt (review_v2.txt) or v1 fallback.
     */
    public function getReviewSystemPromptForVersion(string $version): string;

    /**
     * Return the v2 reviewer tool schema (review_result_v2.json) or v1 fallback.
     *
     * @return array<string, mixed>
     */
    public function getReviewToolSchemaForVersion(string $version): array;

    /**
     * Return the critic system prompt (critic_v1.txt). Same artifact for
     * both providers; loaded once at boot and cached on the driver.
     */
    public function getCriticSystemPrompt(): string;

    /**
     * Return the critic tool schema (critic_result_v1.json).
     *
     * @return array<string, mixed>
     */
    public function getCriticToolSchema(): array;
}
