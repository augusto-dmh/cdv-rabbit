<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function mockHealthyRedis(): void
{
    $horizonMock = Mockery::mock();
    $horizonMock->shouldReceive('zrevrangebyscore')->andReturn(['supervisor-1']);
    $horizonMock->shouldReceive('zscore')->andReturn((string) time());

    Redis::shouldReceive('ping')->andReturn('PONG');
    Redis::shouldReceive('connection')->with('horizon')->andReturn($horizonMock);
}

function mockHealthyHttp(): void
{
    Http::fake([
        'https://api.bitbucket.org/*' => Http::response('', 200),
        'https://api.anthropic.com/*' => Http::response('', 200),
        'https://api.openai.com/*' => Http::response('', 200),
    ]);
}

test('returns 200 with healthy status when all deps are up', function (): void {
    mockHealthyHttp();
    mockHealthyRedis();

    $response = $this->get('/up');

    $response->assertStatus(200)
        ->assertJson(['status' => 'healthy'])
        ->assertJsonStructure([
            'status',
            'checks' => [
                'db' => ['ok', 'duration_ms'],
                'redis' => ['ok', 'duration_ms'],
                'horizon' => ['ok', 'duration_ms', 'supervisor_age_seconds'],
                'bitbucket_api' => ['ok', 'duration_ms'],
                'anthropic_api' => ['ok', 'duration_ms'],
                'openai_api' => ['ok', 'duration_ms'],
            ],
            'version',
        ]);
});

test('returns 503 with degraded status when db is down', function (): void {
    mockHealthyHttp();
    mockHealthyRedis();

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andThrow(new Exception('Connection refused'));

    $response = $this->get('/up');

    $response->assertStatus(503)
        ->assertJson(['status' => 'degraded'])
        ->assertJsonPath('checks.db.ok', false);
});

test('returns 503 when redis is down', function (): void {
    mockHealthyHttp();

    $horizonMock = Mockery::mock();
    $horizonMock->shouldReceive('zrevrangebyscore')->andThrow(new Exception('Redis connection failed'));

    Redis::shouldReceive('ping')->andThrow(new Exception('Redis connection failed'));
    Redis::shouldReceive('connection')->with('horizon')->andReturn($horizonMock);

    $response = $this->get('/up');

    $response->assertStatus(503)
        ->assertJson(['status' => 'degraded'])
        ->assertJsonPath('checks.redis.ok', false);
});

test('returns 503 when bitbucket API returns 5xx', function (): void {
    Http::fake([
        'https://api.bitbucket.org/*' => Http::response('', 503),
        'https://api.anthropic.com/*' => Http::response('', 200),
    ]);
    mockHealthyRedis();

    $response = $this->get('/up');

    $response->assertStatus(503)
        ->assertJson(['status' => 'degraded'])
        ->assertJsonPath('checks.bitbucket_api.ok', false);
});

test('returns 503 when anthropic API times out', function (): void {
    Http::fake([
        'https://api.bitbucket.org/*' => Http::response('', 200),
        'https://api.anthropic.com/*' => fn () => throw new ConnectionException('Connection timed out'),
    ]);
    mockHealthyRedis();

    $response = $this->get('/up');

    $response->assertStatus(503)
        ->assertJson(['status' => 'degraded'])
        ->assertJsonPath('checks.anthropic_api.ok', false);
});

test('returns 503 when horizon supervisor heartbeat is stale', function (): void {
    mockHealthyHttp();

    $staleTimestamp = (string) (time() - 120); // 2 minutes ago — well beyond 60s threshold

    $horizonMock = Mockery::mock();
    $horizonMock->shouldReceive('zrevrangebyscore')->andReturn(['supervisor-1']);
    $horizonMock->shouldReceive('zscore')->andReturn($staleTimestamp);

    Redis::shouldReceive('ping')->andReturn('PONG');
    Redis::shouldReceive('connection')->with('horizon')->andReturn($horizonMock);

    $response = $this->get('/up');

    $response->assertStatus(503)
        ->assertJson(['status' => 'degraded'])
        ->assertJsonPath('checks.horizon.ok', false);

    expect($response->json('checks.horizon.supervisor_age_seconds'))->toBeGreaterThan(60);
});

test('returns 503 when no horizon supervisors are registered', function (): void {
    mockHealthyHttp();

    $horizonMock = Mockery::mock();
    $horizonMock->shouldReceive('zrevrangebyscore')->andReturn([]);

    Redis::shouldReceive('ping')->andReturn('PONG');
    Redis::shouldReceive('connection')->with('horizon')->andReturn($horizonMock);

    $response = $this->get('/up');

    $response->assertStatus(503)
        ->assertJsonPath('checks.horizon.ok', false);
});

test('returns 401 from bitbucket as healthy because service is reachable', function (): void {
    Http::fake([
        'https://api.bitbucket.org/*' => Http::response('', 401),
        'https://api.anthropic.com/*' => Http::response('', 200),
        'https://api.openai.com/*' => Http::response('', 200),
    ]);
    mockHealthyRedis();

    $response = $this->get('/up');

    $response->assertStatus(200)
        ->assertJsonPath('checks.bitbucket_api.ok', true);
});

test('response includes a version string', function (): void {
    mockHealthyHttp();
    mockHealthyRedis();

    $response = $this->get('/up');

    $response->assertJsonStructure(['version']);
    expect($response->json('version'))->toBeString();
});
