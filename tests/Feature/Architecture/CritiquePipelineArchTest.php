<?php

declare(strict_types=1);

use App\Services\Llm\ClaudeReviewer;
use App\Services\Llm\LlmDriverInterface;
use App\Services\Llm\OpenAiReviewer;

// AC45: LlmDriverInterface declares critiqueDraft and both concrete drivers implement it.

test('LlmDriverInterface declares critiqueDraft method', function (): void {
    $reflection = new ReflectionClass(LlmDriverInterface::class);

    expect($reflection->hasMethod('critiqueDraft'))->toBeTrue();
});

test('ClaudeReviewer implements critiqueDraft', function (): void {
    $reflection = new ReflectionClass(ClaudeReviewer::class);

    expect($reflection->hasMethod('critiqueDraft'))->toBeTrue();

    $method = $reflection->getMethod('critiqueDraft');
    expect($method->isPublic())->toBeTrue();
});

test('OpenAiReviewer implements critiqueDraft', function (): void {
    $reflection = new ReflectionClass(OpenAiReviewer::class);

    expect($reflection->hasMethod('critiqueDraft'))->toBeTrue();

    $method = $reflection->getMethod('critiqueDraft');
    expect($method->isPublic())->toBeTrue();
});

test('ClaudeReviewer implements LlmDriverInterface', function (): void {
    expect(is_a(ClaudeReviewer::class, LlmDriverInterface::class, true))->toBeTrue();
});

test('OpenAiReviewer implements LlmDriverInterface', function (): void {
    expect(is_a(OpenAiReviewer::class, LlmDriverInterface::class, true))->toBeTrue();
});
