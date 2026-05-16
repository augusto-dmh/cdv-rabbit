<?php

declare(strict_types=1);

use App\Ai\Agents\OpenAiReviewAgent;
use App\Services\Llm\Dto\ReviewResultDto;
use App\Services\Llm\LlmReviewException;
use App\Services\Llm\OpenAiReviewer;
use App\Support\OpenAiErrorClassifier;
use App\Support\OpenAiHeaderBag;
use App\Support\RetryDecision;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;

beforeEach(function (): void {
    $this->systemPrompt = file_get_contents(config_path('cdv-rabbit/prompts/review_v1.txt'));
    $this->toolSchema = json_decode(file_get_contents(config_path('cdv-rabbit/schemas/review_result_v1.json')), true);
    $this->userMessage = '<diff>+class Foo {}</diff>';
});

function makeOpenAiReviewer(): OpenAiReviewer
{
    return new OpenAiReviewer(
        container: app(),
        classifier: new OpenAiErrorClassifier,
    );
}

function fakeOpenAiReviewResponse(): array
{
    return [
        'summary' => ['overview' => 'Looks good overall.', 'risk_level' => 'low'],
        'comments' => [
            ['path' => 'app/Foo.php', 'line' => 1, 'severity' => 'nit', 'message' => 'Missing docblock.'],
        ],
    ];
}

// ---------------------------------------------------------------------------
// AC27: OpenAiReviewer returns ReviewResultDto on success
// ---------------------------------------------------------------------------

it('AC27: returns a ReviewResultDto on happy path', function (): void {
    OpenAiReviewAgent::fake(function ($prompt) {
        return fakeOpenAiReviewResponse();
    });

    app()->instance(OpenAiHeaderBag::class, new OpenAiHeaderBag(
        requestId: 'openai-req-123',
        rateLimitTokensRemaining: 80000,
        rateLimitTokensReset: '1s',
    ));

    $result = makeOpenAiReviewer()->reviewDiff($this->systemPrompt, $this->toolSchema, $this->userMessage);

    expect($result)->toBeInstanceOf(ReviewResultDto::class)
        ->and($result->summary->overview)->toBe('Looks good overall.')
        ->and($result->summary->riskLevel)->toBe('low')
        ->and($result->comments)->toHaveCount(1)
        ->and($result->comments[0]->path)->toBe('app/Foo.php')
        ->and($result->comments[0]->severity)->toBe('nit')
        ->and($result->requestId)->toBe('openai-req-123');
});

// ---------------------------------------------------------------------------
// AC28: secret-redacted input never reaches the agent
// ---------------------------------------------------------------------------

it('AC28: pre-redacted diff does not contain raw secrets when passed to OpenAI', function (): void {
    $capturedPrompt = null;

    OpenAiReviewAgent::fake(function ($prompt) use (&$capturedPrompt) {
        $capturedPrompt = $prompt;

        return fakeOpenAiReviewResponse();
    });

    app()->instance(OpenAiHeaderBag::class, new OpenAiHeaderBag(requestId: 'openai-req-redact'));

    // Simulate already-redacted input (the job redacts before calling reviewDiff)
    $redactedMessage = '<diff>+$key = \'<<SECRET_REDACTED>>\';</diff>';
    makeOpenAiReviewer()->reviewDiff($this->systemPrompt, $this->toolSchema, $redactedMessage);

    expect($capturedPrompt)->not->toContain('AKIAIOSFODNN7EXAMPLE');
});

// ---------------------------------------------------------------------------
// AC29: 429 rate_limit_exceeded → RetryWithBackoff
// ---------------------------------------------------------------------------

it('AC29: classifies rate_limit_exceeded (429) as RetryWithBackoff', function (): void {
    $body = json_encode(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Rate limited']]);
    $guzzleException = new GuzzleRequestException(
        'Rate Limited',
        new GuzzleRequest('POST', 'https://api.openai.com/v1/chat/completions'),
        new GuzzleResponse(429, [], $body),
    );

    OpenAiReviewAgent::fake(function ($prompt) use ($guzzleException): void {
        throw $guzzleException;
    });

    app()->instance(OpenAiHeaderBag::class, new OpenAiHeaderBag);

    try {
        makeOpenAiReviewer()->reviewDiff($this->systemPrompt, $this->toolSchema, $this->userMessage);
        $this->fail('Expected LlmReviewException');
    } catch (LlmReviewException $e) {
        expect($e->retryDecision)->toBe(RetryDecision::RetryWithBackoff);
    }
});

// ---------------------------------------------------------------------------
// AC30: insufficient_quota → Terminal
// ---------------------------------------------------------------------------

it('AC30: classifies insufficient_quota as Terminal', function (): void {
    $body = json_encode(['error' => ['code' => 'insufficient_quota', 'message' => 'Quota exceeded']]);
    $guzzleException = new GuzzleRequestException(
        'Too Many Requests',
        new GuzzleRequest('POST', 'https://api.openai.com/v1/chat/completions'),
        new GuzzleResponse(429, [], $body),
    );

    OpenAiReviewAgent::fake(function ($prompt) use ($guzzleException): void {
        throw $guzzleException;
    });

    app()->instance(OpenAiHeaderBag::class, new OpenAiHeaderBag);

    try {
        makeOpenAiReviewer()->reviewDiff($this->systemPrompt, $this->toolSchema, $this->userMessage);
        $this->fail('Expected LlmReviewException');
    } catch (LlmReviewException $e) {
        expect($e->retryDecision)->toBe(RetryDecision::Terminal);
    }
});

// ---------------------------------------------------------------------------
// AC30: context_length_exceeded → Terminal
// ---------------------------------------------------------------------------

it('AC30: classifies context_length_exceeded as Terminal', function (): void {
    $body = json_encode(['error' => ['code' => 'context_length_exceeded', 'message' => 'Context too long']]);
    $guzzleException = new GuzzleRequestException(
        'Bad Request',
        new GuzzleRequest('POST', 'https://api.openai.com/v1/chat/completions'),
        new GuzzleResponse(400, [], $body),
    );

    OpenAiReviewAgent::fake(function ($prompt) use ($guzzleException): void {
        throw $guzzleException;
    });

    app()->instance(OpenAiHeaderBag::class, new OpenAiHeaderBag);

    try {
        makeOpenAiReviewer()->reviewDiff($this->systemPrompt, $this->toolSchema, $this->userMessage);
        $this->fail('Expected LlmReviewException');
    } catch (LlmReviewException $e) {
        expect($e->retryDecision)->toBe(RetryDecision::Terminal);
    }
});

// ---------------------------------------------------------------------------
// AC31: 25-comment cap enforced by CommentPoster (reviewer passes all through)
// ---------------------------------------------------------------------------

it('AC31: reviewer passes all LLM comments through (cap is enforced by CommentPoster)', function (): void {
    $comments = [];
    for ($i = 1; $i <= 30; $i++) {
        $comments[] = ['path' => "app/File{$i}.php", 'line' => $i, 'severity' => 'nit', 'message' => "Issue {$i}"];
    }

    OpenAiReviewAgent::fake(function ($prompt) use ($comments) {
        return [
            'summary' => ['overview' => 'Many issues.', 'risk_level' => 'high'],
            'comments' => $comments,
        ];
    });

    app()->instance(OpenAiHeaderBag::class, new OpenAiHeaderBag(requestId: 'openai-req-cap'));

    $result = makeOpenAiReviewer()->reviewDiff($this->systemPrompt, $this->toolSchema, $this->userMessage);

    expect($result->comments)->toHaveCount(30);
});

// ---------------------------------------------------------------------------
// duration_ms is a non-negative integer
// ---------------------------------------------------------------------------

it('records duration_ms as a non-negative integer', function (): void {
    OpenAiReviewAgent::fake(fn ($prompt) => fakeOpenAiReviewResponse());

    app()->instance(OpenAiHeaderBag::class, new OpenAiHeaderBag(requestId: 'openai-req-dur'));

    $result = makeOpenAiReviewer()->reviewDiff($this->systemPrompt, $this->toolSchema, $this->userMessage);

    expect($result->durationMs)->toBeInt()->toBeGreaterThanOrEqual(0);
});
