<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Queue\BindWorkspaceMiddleware;
use Tests\TestCase;

uses(TestCase::class);

test('middleware binds workspace context before handle and clears it after', function (): void {
    $context = new WorkspaceContext;
    $middleware = new BindWorkspaceMiddleware($context);

    $job = new class
    {
        public int $workspaceId = 42;
    };

    $contextDuringHandle = null;

    $middleware->handle($job, function ($j) use ($context, &$contextDuringHandle) {
        $contextDuringHandle = $context->current();
    });

    expect($contextDuringHandle)->toBe(42);
    expect($context->bound())->toBeFalse();
});

test('middleware clears context even when handle throws', function (): void {
    $context = new WorkspaceContext;
    $middleware = new BindWorkspaceMiddleware($context);

    $job = new class
    {
        public int $workspaceId = 7;
    };

    expect(fn () => $middleware->handle($job, function () {
        throw new RuntimeException('job failed');
    }))->toThrow(RuntimeException::class);

    expect($context->bound())->toBeFalse();
});

test('middleware throws when job has no workspaceId property', function (): void {
    $context = new WorkspaceContext;
    $middleware = new BindWorkspaceMiddleware($context);

    $job = new class {};

    expect(fn () => $middleware->handle($job, fn () => null))
        ->toThrow(InvalidArgumentException::class);
});
