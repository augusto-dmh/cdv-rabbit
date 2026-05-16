<?php

declare(strict_types=1);

/**
 * Gated live-API eval test. Skipped by default; enable by exporting
 * RABBIT_EVAL_LIVE=1 before running the suite. Hits real Anthropic + OpenAI
 * APIs and asserts recall/precision thresholds on the golden corpus.
 *
 * AC39: anthropic recall >= 0.7
 * AC40: openai recall >= 0.7
 *
 * Lives outside tests/Feature so it does not run in normal CI; the eval
 * harness is invoked by operators via `php artisan rabbit:eval` with the
 * desired thresholds.
 */
beforeEach(function (): void {
    if (env('RABBIT_EVAL_LIVE') !== '1') {
        $this->markTestSkipped('Requires live API; set RABBIT_EVAL_LIVE=1 to run.');
    }
});

it('meets recall >= 0.7 on anthropic across the golden corpus (AC39)', function (): void {
    $this->artisan('rabbit:eval', [
        '--provider' => ['anthropic'],
        '--schema' => 'v1',
        '--threshold-recall' => 0.7,
        '--threshold-precision' => 0.5,
    ])->assertExitCode(0);
});

it('meets recall >= 0.7 on openai across the golden corpus (AC40)', function (): void {
    $this->artisan('rabbit:eval', [
        '--provider' => ['openai'],
        '--schema' => 'v1',
        '--threshold-recall' => 0.7,
        '--threshold-precision' => 0.5,
    ])->assertExitCode(0);
});
