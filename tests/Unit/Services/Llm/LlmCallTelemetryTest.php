<?php

declare(strict_types=1);

use App\Enums\LlmCallRole;
use App\Models\Review;
use App\Models\ReviewsLlmCall;
use App\Services\Llm\Dto\ReviewResultDto;
use App\Services\Llm\Dto\ReviewSummaryDto;
use App\Services\Llm\LlmCallTelemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('AC22: record persists all four token fields and request_id to reviews_llm_calls', function (): void {
    $review = Review::factory()->create();

    $dto = new ReviewResultDto(
        summary: new ReviewSummaryDto(overview: 'Looks good.', riskLevel: 'low'),
        comments: [],
        inputTokens: 500,
        cacheCreationInputTokens: 300,
        cacheReadInputTokens: 100,
        outputTokens: 200,
        requestId: 'req_abc123',
        rateLimitTokensRemaining: 50000,
        rateLimitTokensReset: '2026-05-14T01:00:00Z',
        durationMs: 1234,
    );

    $row = (new LlmCallTelemetry)->record($review, 'claude-sonnet-4-6', LlmCallRole::Review, $dto);

    expect($row)->toBeInstanceOf(ReviewsLlmCall::class)
        ->and($row->review_id)->toBe($review->id)
        ->and($row->workspace_id)->toBe($review->workspace_id)
        ->and($row->model_id)->toBe('claude-sonnet-4-6')
        ->and($row->role)->toBe(LlmCallRole::Review)
        ->and($row->input_tokens)->toBe(500)
        ->and($row->cache_creation_input_tokens)->toBe(300)
        ->and($row->cache_read_input_tokens)->toBe(100)
        ->and($row->output_tokens)->toBe(200)
        ->and($row->request_id)->toBe('req_abc123')
        ->and($row->ratelimit_tokens_remaining)->toBe(50000)
        ->and($row->duration_ms)->toBe(1234)
        ->and($row->http_status)->toBe(200)
        ->and($row->error_type)->toBeNull();

    $this->assertDatabaseHas('reviews_llm_calls', [
        'review_id' => $review->id,
        'request_id' => 'req_abc123',
        'input_tokens' => 500,
        'cache_creation_input_tokens' => 300,
        'cache_read_input_tokens' => 100,
        'output_tokens' => 200,
    ]);
});

test('AC22: record with null request_id on error path stores error_type', function (): void {
    $review = Review::factory()->create();

    $dto = new ReviewResultDto(
        summary: new ReviewSummaryDto(overview: '', riskLevel: 'low'),
        comments: [],
        inputTokens: 100,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 0,
        requestId: null,
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 50,
    );

    $row = (new LlmCallTelemetry)->record($review, 'claude-sonnet-4-6', LlmCallRole::Review, $dto, 429, 'rate_limit_error');

    expect($row->request_id)->toBeNull()
        ->and($row->http_status)->toBe(429)
        ->and($row->error_type)->toBe('rate_limit_error');
});

test('record persists triage role correctly', function (): void {
    $review = Review::factory()->create();

    $dto = new ReviewResultDto(
        summary: new ReviewSummaryDto(overview: 'Triage result.', riskLevel: 'low'),
        comments: [],
        inputTokens: 50,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 50,
        outputTokens: 10,
        requestId: 'req_triage1',
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 100,
    );

    $row = (new LlmCallTelemetry)->record($review, 'claude-haiku-4-5-20251001', LlmCallRole::Triage, $dto);

    expect($row->role)->toBe(LlmCallRole::Triage)
        ->and($row->model_id)->toBe('claude-haiku-4-5-20251001')
        ->and($row->cache_read_input_tokens)->toBe(50);
});
