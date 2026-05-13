<?php

declare(strict_types=1);

use App\Events\RedactionApplied;
use App\Queue\RedactingFailedJobProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class);

test('redacts top-level sensitive keys from payload', function (): void {
    Event::fake();

    $capturedPayload = '';
    $inner = Mockery::mock(FailedJobProviderInterface::class);
    $inner->shouldReceive('log')
        ->once()
        ->withArgs(function ($conn, $queue, $payload) use (&$capturedPayload) {
            $capturedPayload = $payload;

            return true;
        })
        ->andReturn('fake-uuid');

    $provider = new RedactingFailedJobProvider($inner, app(Dispatcher::class));

    $payload = json_encode([
        'job' => 'App\\Jobs\\ReviewPullRequestJob',
        'diff' => 'function foo() { return 42; }',
        'workspace_id' => 7,
    ]);

    $provider->log('redis', 'reviews', $payload, new Exception('oops'));

    $decoded = json_decode($capturedPayload, true);
    expect($decoded['diff'])->toBe('<<REDACTED>>');
    expect($decoded['workspace_id'])->toBe(7);

    Event::assertDispatched(RedactionApplied::class, fn ($e) => $e->workspaceId === 7 && in_array('diff', $e->redactedKeys));
});

test('redacts sensitive keys nested inside payload', function (): void {
    Event::fake();

    $capturedPayload = '';
    $inner = Mockery::mock(FailedJobProviderInterface::class);
    $inner->shouldReceive('log')
        ->once()
        ->withArgs(function ($conn, $queue, $payload) use (&$capturedPayload) {
            $capturedPayload = $payload;

            return true;
        })
        ->andReturn('fake-uuid');

    $provider = new RedactingFailedJobProvider($inner, app(Dispatcher::class));

    $payload = json_encode([
        'job' => 'App\\Jobs\\ReviewPullRequestJob',
        'data' => [
            'command' => [
                'diff' => 'some diff content',
                'patch' => 'some patch content',
            ],
            'workspace_id' => 3,
        ],
    ]);

    $provider->log('redis', 'reviews', $payload, new Exception('oops'));

    $decoded = json_decode($capturedPayload, true);
    expect($decoded['data']['command']['diff'])->toBe('<<REDACTED>>');
    expect($decoded['data']['command']['patch'])->toBe('<<REDACTED>>');

    Event::assertDispatched(RedactionApplied::class);
});

test('passes through payload without sensitive keys unchanged', function (): void {
    Event::fake();

    $capturedPayload = '';
    $inner = Mockery::mock(FailedJobProviderInterface::class);
    $inner->shouldReceive('log')
        ->once()
        ->withArgs(function ($conn, $queue, $payload) use (&$capturedPayload) {
            $capturedPayload = $payload;

            return true;
        })
        ->andReturn('fake-uuid');

    $provider = new RedactingFailedJobProvider($inner, app(Dispatcher::class));

    $payload = json_encode([
        'job' => 'App\\Jobs\\ReviewPullRequestJob',
        'workspace_id' => 5,
        'repository_id' => 12,
        'pull_request_number' => 42,
    ]);

    $provider->log('redis', 'reviews', $payload, new Exception('oops'));

    $decoded = json_decode($capturedPayload, true);
    expect($decoded['workspace_id'])->toBe(5);
    expect($decoded['repository_id'])->toBe(12);
    expect($decoded['pull_request_number'])->toBe(42);

    Event::assertNotDispatched(RedactionApplied::class);
});
