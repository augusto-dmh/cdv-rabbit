<?php

declare(strict_types=1);

use App\Services\Llm\Dto\CritiqueResultDto;
use App\Services\Llm\Dto\DraftReviewDto;
use App\Services\Llm\Dto\ReviewFindingDto;
use App\Services\Llm\Dto\ReviewSummaryV2Dto;

function makeFinding(int $line = 10, string $sev = 'medium', string $cat = 'bug', ?string $sug = null): ReviewFindingDto
{
    return new ReviewFindingDto(
        path: 'app/Foo.php',
        line: $line,
        severity: $sev,
        category: $cat,
        message: "Issue at line {$line}",
        suggestion: $sug,
    );
}

function makeSummary(): ReviewSummaryV2Dto
{
    return new ReviewSummaryV2Dto(
        overview: 'overview',
        riskLevel: 'low',
        walkthrough: 'walkthrough',
        filesAnalyzed: [],
    );
}

it('approvedFindingIndices returns sorted unique approved indices', function (): void {
    $dto = new CritiqueResultDto(
        decisions: [
            ['finding_index' => 2, 'verdict' => 'approve', 'reason' => ''],
            ['finding_index' => 0, 'verdict' => 'reject', 'reason' => 'fake'],
            ['finding_index' => 1, 'verdict' => 'approve', 'reason' => ''],
            ['finding_index' => 1, 'verdict' => 'approve', 'reason' => 'dup'],
        ],
        inputTokens: 100,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 50,
        requestId: 'req_c1',
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 500,
    );

    expect($dto->approvedFindingIndices())->toBe([1, 2]);
    expect($dto->rejectedFindingIndices())->toBe([0]);
});

it('rejectedFindings pairs each rejected verdict with the draft finding and reason', function (): void {
    $draft = new DraftReviewDto(
        summary: makeSummary(),
        findings: [makeFinding(10), makeFinding(20), makeFinding(30)],
        nitpicks: [],
        inputTokens: 0,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 0,
        requestId: null,
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 0,
    );

    $critique = new CritiqueResultDto(
        decisions: [
            ['finding_index' => 0, 'verdict' => 'approve', 'reason' => 'real'],
            ['finding_index' => 1, 'verdict' => 'reject', 'reason' => 'context line'],
            ['finding_index' => 2, 'verdict' => 'reject', 'reason' => 'duplicate'],
        ],
        inputTokens: 0,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 0,
        requestId: null,
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 0,
    );

    $rejected = $critique->rejectedFindings($draft);

    expect($rejected)->toHaveCount(2);
    expect($rejected[0]['finding']->line)->toBe(20);
    expect($rejected[0]['reason'])->toBe('context line');
    expect($rejected[1]['finding']->line)->toBe(30);
    expect($rejected[1]['reason'])->toBe('duplicate');
});

it('telemetry fields are preserved on the DTO', function (): void {
    $critique = new CritiqueResultDto(
        decisions: [],
        inputTokens: 1234,
        cacheCreationInputTokens: 5678,
        cacheReadInputTokens: 9012,
        outputTokens: 345,
        requestId: 'req_t1',
        rateLimitTokensRemaining: 50000,
        rateLimitTokensReset: '2026-05-16T01:00:00Z',
        durationMs: 999,
    );

    expect($critique->inputTokens)->toBe(1234)
        ->and($critique->cacheCreationInputTokens)->toBe(5678)
        ->and($critique->cacheReadInputTokens)->toBe(9012)
        ->and($critique->outputTokens)->toBe(345)
        ->and($critique->requestId)->toBe('req_t1')
        ->and($critique->rateLimitTokensRemaining)->toBe(50000)
        ->and($critique->rateLimitTokensReset)->toBe('2026-05-16T01:00:00Z')
        ->and($critique->durationMs)->toBe(999);
});

it('DraftReviewDto::withFindingsAtIndices keeps only the requested findings in order', function (): void {
    $draft = new DraftReviewDto(
        summary: makeSummary(),
        findings: [makeFinding(10), makeFinding(20), makeFinding(30), makeFinding(40)],
        nitpicks: [],
        inputTokens: 1,
        cacheCreationInputTokens: 2,
        cacheReadInputTokens: 3,
        outputTokens: 4,
        requestId: 'r',
        rateLimitTokensRemaining: 5,
        rateLimitTokensReset: '2026-05-16T00:00:00Z',
        durationMs: 6,
    );

    $filtered = $draft->withFindingsAtIndices([0, 3]);

    expect($filtered->findings)->toHaveCount(2)
        ->and($filtered->findings[0]->line)->toBe(10)
        ->and($filtered->findings[1]->line)->toBe(40)
        ->and($filtered->inputTokens)->toBe(1)
        ->and($filtered->requestId)->toBe('r');
});

it('DraftReviewDto::toCriticInputArray exposes 0-based finding_index for the critic prompt', function (): void {
    $draft = new DraftReviewDto(
        summary: makeSummary(),
        findings: [makeFinding(10, 'high'), makeFinding(20, 'low')],
        nitpicks: [],
        inputTokens: 0,
        cacheCreationInputTokens: 0,
        cacheReadInputTokens: 0,
        outputTokens: 0,
        requestId: null,
        rateLimitTokensRemaining: null,
        rateLimitTokensReset: null,
        durationMs: 0,
    );

    $payload = $draft->toCriticInputArray();

    expect($payload['findings'])->toHaveCount(2)
        ->and($payload['findings'][0]['finding_index'])->toBe(0)
        ->and($payload['findings'][1]['finding_index'])->toBe(1)
        ->and($payload['findings'][0]['severity'])->toBe('high')
        ->and($payload['summary']['risk_level'])->toBe('low');
});
