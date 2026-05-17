<?php

declare(strict_types=1);

use App\Ai\Agents\OpenAiCriticAgent;
use App\Ai\Agents\OpenAiJudgeAgent;
use App\Ai\Agents\OpenAiReviewAgent;
use Tests\TestCase;

uses(TestCase::class);

// ---------------------------------------------------------------------------
// OpenAiReviewAgent
// ---------------------------------------------------------------------------

test('OpenAiReviewAgent model() returns gpt-4o by default', function (): void {
    config(['cdv-rabbit.llm_models.openai_review' => 'gpt-4o']);

    $agent = new OpenAiReviewAgent;

    expect($agent->model())->toBe('gpt-4o');
});

test('OpenAiReviewAgent model() returns value from config override', function (): void {
    config(['cdv-rabbit.llm_models.openai_review' => 'gpt-5.5']);

    $agent = new OpenAiReviewAgent;

    expect($agent->model())->toBe('gpt-5.5');
});

// ---------------------------------------------------------------------------
// OpenAiCriticAgent
// ---------------------------------------------------------------------------

test('OpenAiCriticAgent model() returns gpt-4o by default', function (): void {
    config(['cdv-rabbit.llm_models.openai_critic' => 'gpt-4o']);

    $agent = new OpenAiCriticAgent;

    expect($agent->model())->toBe('gpt-4o');
});

test('OpenAiCriticAgent model() returns value from config override', function (): void {
    config(['cdv-rabbit.llm_models.openai_critic' => 'gpt-4o-mini']);

    $agent = new OpenAiCriticAgent;

    expect($agent->model())->toBe('gpt-4o-mini');
});

// ---------------------------------------------------------------------------
// OpenAiJudgeAgent
// ---------------------------------------------------------------------------

test('OpenAiJudgeAgent model() returns gpt-4o by default', function (): void {
    config(['cdv-rabbit.llm_models.openai_judge' => 'gpt-4o']);

    $agent = new OpenAiJudgeAgent;

    expect($agent->model())->toBe('gpt-4o');
});

test('OpenAiJudgeAgent model() returns value from config override', function (): void {
    config(['cdv-rabbit.llm_models.openai_judge' => 'gpt-5.5']);

    $agent = new OpenAiJudgeAgent;

    expect($agent->model())->toBe('gpt-5.5');
});

// ---------------------------------------------------------------------------
// Default: config key holds the env() default of 'gpt-4o' (unset env var)
// ---------------------------------------------------------------------------

test('OpenAiReviewAgent model() returns gpt-4o when env var is not overridden', function (): void {
    // Simulate the default: env var absent → env('CDV_RABBIT_OPENAI_REVIEW_MODEL', 'gpt-4o') = 'gpt-4o'.
    config(['cdv-rabbit.llm_models.openai_review' => 'gpt-4o']);

    $agent = new OpenAiReviewAgent;

    expect($agent->model())->toBe('gpt-4o');
});

test('OpenAiCriticAgent model() returns gpt-4o when env var is not overridden', function (): void {
    config(['cdv-rabbit.llm_models.openai_critic' => 'gpt-4o']);

    $agent = new OpenAiCriticAgent;

    expect($agent->model())->toBe('gpt-4o');
});

test('OpenAiJudgeAgent model() returns gpt-4o when env var is not overridden', function (): void {
    config(['cdv-rabbit.llm_models.openai_judge' => 'gpt-4o']);

    $agent = new OpenAiJudgeAgent;

    expect($agent->model())->toBe('gpt-4o');
});
