<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Exceptions\WorkspaceContextMissingException;
use Tests\TestCase;

uses(TestCase::class);

test('bind and current round-trip', function (): void {
    $context = new WorkspaceContext;
    $context->bind(42);

    expect($context->current())->toBe(42);
});

test('current throws when unbound', function (): void {
    $context = new WorkspaceContext;

    expect(fn () => $context->current())->toThrow(WorkspaceContextMissingException::class);
});

test('optional returns null when unbound', function (): void {
    $context = new WorkspaceContext;

    expect($context->optional())->toBeNull();
});

test('clear resets state', function (): void {
    $context = new WorkspaceContext;
    $context->bind(99);
    $context->clear();

    expect($context->bound())->toBeFalse();
    expect($context->optional())->toBeNull();
});

test('singleton scope returns same instance across resolves', function (): void {
    $a = app(WorkspaceContext::class);
    $b = app(WorkspaceContext::class);

    expect($a)->toBe($b);
});
