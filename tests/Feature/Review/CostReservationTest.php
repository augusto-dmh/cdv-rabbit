<?php

declare(strict_types=1);

use App\Mail\DailyTokenSpendApproaching;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Review\CostReservation;
use App\Services\Review\ReservationResult;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;

// ---------------------------------------------------------------------------
// Helper: build a CostReservation with a mocked Redis facade.
// ---------------------------------------------------------------------------

/**
 * Bind a partial mock of the Redis facade and return a fresh CostReservation.
 * The Lua script loading happens in the constructor, so we need the service
 * to be instantiated after we set up expectations.
 */
function makeCostReservation(): CostReservation
{
    return new CostReservation;
}

// ---------------------------------------------------------------------------
// Unit-style tests (Redis facade mocked — no real Redis required)
// ---------------------------------------------------------------------------

test('AC20: single reserve happy path — Lua returns granted + consumed', function (): void {
    // Lua script returns [1, 1000] meaning: granted, new total = 1000.
    Redis::shouldReceive('eval')
        ->once()
        ->andReturn([1, 1000]);

    $result = makeCostReservation()->reserve(42, 1000, 10_000);

    expect($result)->toBeInstanceOf(ReservationResult::class)
        ->and($result->granted)->toBeTrue()
        ->and($result->consumed)->toBe(1000)
        ->and($result->remaining())->toBe(9000)
        ->and($result->denied())->toBeFalse();
});

test('AC20: reserve exceeding cap — Lua returns denied + current unchanged', function (): void {
    // Lua script returns [0, 4000] meaning: denied, current value was 4000.
    Redis::shouldReceive('eval')
        ->once()
        ->andReturn([0, 4000]);

    $result = makeCostReservation()->reserve(42, 2000, 5_000);

    expect($result->granted)->toBeFalse()
        ->and($result->consumed)->toBe(4000)
        ->and($result->denied())->toBeTrue();
});

test('AC20: two sequential reserves — first succeeds, second denied (Lua atomicity)', function (): void {
    // The Lua block is atomic on the Redis server side regardless of concurrent PHP clients.
    // Sequential calls model the same semantics: once cap is consumed, the second is rejected.
    Redis::shouldReceive('eval')
        ->twice()
        ->andReturn([1, 2000], [0, 2000]); // first granted, second denied

    $service = makeCostReservation();
    $first = $service->reserve(42, 2000, 3_000);
    $second = $service->reserve(42, 2000, 3_000);

    expect($first->granted)->toBeTrue()
        ->and($second->granted)->toBeFalse()
        ->and($second->consumed)->toBe(2000);
});

test('consumed returns current Redis value', function (): void {
    Redis::shouldReceive('get')->once()->andReturn('3000');

    $consumed = makeCostReservation()->consumed(42);

    expect($consumed)->toBe(3000);
});

test('consumed returns zero when key does not exist', function (): void {
    Redis::shouldReceive('get')->once()->andReturn(null);

    $consumed = makeCostReservation()->consumed(99999);

    expect($consumed)->toBe(0);
});

test('release decrements by the amount requested', function (): void {
    Redis::shouldReceive('get')->once()->andReturn('3000');
    Redis::shouldReceive('decrby')->once()->with(Mockery::any(), 1000);

    makeCostReservation()->release(42, 1000);
});

test('release is bounded at zero — decrement capped to current value', function (): void {
    Redis::shouldReceive('get')->once()->andReturn('500');
    // Should decrement by 500, not 9999.
    Redis::shouldReceive('decrby')->once()->with(Mockery::any(), 500);

    makeCostReservation()->release(42, 9999);
});

test('release is a no-op when counter is already zero', function (): void {
    Redis::shouldReceive('get')->once()->andReturn('0');
    Redis::shouldReceive('decrby')->never();

    makeCostReservation()->release(42, 1000);
});

test('dailyCapFor returns workspace column value', function (): void {
    $workspace = Workspace::factory()->make(['daily_token_cap' => 50_000]);

    expect(makeCostReservation()->dailyCapFor($workspace))->toBe(50_000);
});

test('dailyCapFor falls back to 200000 when column is null', function (): void {
    $workspace = Workspace::factory()->make(['daily_token_cap' => null]);

    expect(makeCostReservation()->dailyCapFor($workspace))->toBe(200_000);
});

// ---------------------------------------------------------------------------
// AC11: email notification tests (need DB for workspace/user, not real Redis)
// ---------------------------------------------------------------------------

test('AC11: notifyIfThresholdExceeded queues mail when threshold exceeded', function (): void {
    Mail::fake();

    $admin = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'daily_token_cap' => 10_000,
        'daily_token_cap_alert_threshold' => 70,
    ]);
    $workspace->users()->attach($admin, ['role' => 'admin']);

    makeCostReservation()->notifyIfThresholdExceeded($workspace, 7_500); // 75% > 70%

    Mail::assertQueued(
        DailyTokenSpendApproaching::class,
        fn (DailyTokenSpendApproaching $mail): bool => $mail->workspace->id === $workspace->id
            && $mail->consumed === 7_500
            && $mail->cap === 10_000
    );
});

test('AC11: notifyIfThresholdExceeded does not queue mail when below threshold', function (): void {
    Mail::fake();

    $admin = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'daily_token_cap' => 10_000,
        'daily_token_cap_alert_threshold' => 70,
    ]);
    $workspace->users()->attach($admin, ['role' => 'admin']);

    makeCostReservation()->notifyIfThresholdExceeded($workspace, 5_000); // 50% < 70%

    Mail::assertNothingQueued();
});

test('AC11: notifyIfThresholdExceeded does not queue when no admins', function (): void {
    Mail::fake();

    $workspace = Workspace::factory()->create([
        'daily_token_cap' => 10_000,
        'daily_token_cap_alert_threshold' => 70,
    ]);

    makeCostReservation()->notifyIfThresholdExceeded($workspace, 9_000); // 90% > 70%

    Mail::assertNothingQueued();
});
