<?php

declare(strict_types=1);

use App\Events\RedactionApplied;
use App\Queue\RedactingFailedJobProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Event;

test('sensitive diff key is redacted before persistence and <<REDACTED>> is stored', function (): void {
    Event::fake();

    $capturedPayload = null;

    $inner = Mockery::mock(FailedJobProviderInterface::class);
    $inner->shouldReceive('log')
        ->once()
        ->withArgs(function ($conn, $queue, $payload) use (&$capturedPayload) {
            $capturedPayload = $payload;

            return true;
        })
        ->andReturn('uuid-1');

    $provider = new RedactingFailedJobProvider($inner, app(Dispatcher::class));

    $payload = json_encode([
        'job' => 'App\\Jobs\\ReviewPullRequestJob',
        'workspace_id' => 5,
        'diff' => "+++ b/foo.php\n- old line\n+ new line",
        'patch' => 'some patch data',
        'hunk' => '@@ -1,3 +1,4 @@',
    ]);

    $provider->log('redis', 'reviews', $payload, new Exception('failed'));

    $decoded = json_decode($capturedPayload, true);

    expect($decoded['diff'])->toBe('<<REDACTED>>');
    expect($decoded['patch'])->toBe('<<REDACTED>>');
    expect($decoded['hunk'])->toBe('<<REDACTED>>');
    expect($decoded['workspace_id'])->toBe(5);

    expect($capturedPayload)->not->toContain('+++ b/foo.php');
    expect($capturedPayload)->not->toContain('some patch data');

    Event::assertDispatched(RedactionApplied::class, fn ($e) => $e->workspaceId === 5);
});

test('payload without sensitive keys is stored unchanged', function (): void {
    Event::fake();

    $capturedPayload = null;

    $inner = Mockery::mock(FailedJobProviderInterface::class);
    $inner->shouldReceive('log')
        ->once()
        ->withArgs(function ($conn, $queue, $payload) use (&$capturedPayload) {
            $capturedPayload = $payload;

            return true;
        })
        ->andReturn('uuid-2');

    $provider = new RedactingFailedJobProvider($inner, app(Dispatcher::class));

    $payload = json_encode([
        'job' => 'App\\Jobs\\ReviewPullRequestJob',
        'workspace_id' => 9,
        'repository_id' => 3,
        'pull_request_number' => 17,
    ]);

    $provider->log('redis', 'reviews', $payload, new Exception('failed'));

    $decoded = json_decode($capturedPayload, true);

    expect($decoded['workspace_id'])->toBe(9);
    expect($decoded['repository_id'])->toBe(3);
    expect($decoded['pull_request_number'])->toBe(17);

    Event::assertNotDispatched(RedactionApplied::class);
});
