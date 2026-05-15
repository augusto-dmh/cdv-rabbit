<?php

declare(strict_types=1);

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $runners = [
            'db' => fn () => $this->checkDb(),
            'redis' => fn () => $this->checkRedis(),
            'horizon' => fn () => $this->checkHorizon(),
            'bitbucket_api' => fn () => $this->checkBitbucket(),
            'anthropic_api' => fn () => $this->checkAnthropic(),
            'openai_api' => fn () => $this->checkOpenAi(),
        ];

        // Concurrency::run spawns child processes — mocks don't survive the fork.
        // Run sequentially in test env so facade mocks are respected.
        if (app()->runningUnitTests()) {
            $checks = collect($runners)->map(fn ($fn) => $fn())->all();
        } else {
            $checks = Concurrency::run($runners);
        }

        $allOk = collect($checks)->every(fn ($check) => $check['ok']);

        return response()->json([
            'status' => $allOk ? 'healthy' : 'degraded',
            'checks' => $checks,
            'version' => $this->resolveVersion(),
        ], $allOk ? 200 : 503);
    }

    private function checkDb(): array
    {
        $start = hrtime(true);

        try {
            DB::connection()->getPdo();
            $ok = true;
        } catch (\Throwable) {
            $ok = false;
        }

        return ['ok' => $ok, 'duration_ms' => $this->ms($start)];
    }

    private function checkRedis(): array
    {
        $start = hrtime(true);

        try {
            Redis::ping();
            $ok = true;
        } catch (\Throwable) {
            $ok = false;
        }

        return ['ok' => $ok, 'duration_ms' => $this->ms($start)];
    }

    private function checkHorizon(): array
    {
        $start = hrtime(true);

        try {
            /** @var Connection $connection */
            $connection = Redis::connection('horizon');

            // `masters` is a sorted set; score = UNIX timestamp of last heartbeat
            $names = $connection->zrevrangebyscore('masters', '+inf', '-inf', ['limit' => [0, 1]]);

            if (empty($names)) {
                return ['ok' => false, 'duration_ms' => $this->ms($start), 'supervisor_age_seconds' => null];
            }

            $score = $connection->zscore('masters', $names[0]);
            $ageSeconds = (int) (time() - (int) $score);
            $ok = $ageSeconds <= 60;

            return ['ok' => $ok, 'duration_ms' => $this->ms($start), 'supervisor_age_seconds' => $ageSeconds];
        } catch (\Throwable) {
            return ['ok' => false, 'duration_ms' => $this->ms($start), 'supervisor_age_seconds' => null];
        }
    }

    private function checkBitbucket(): array
    {
        $start = hrtime(true);

        try {
            $response = Http::timeout(2)->head(config('services.bitbucket.base_url'));
            // 200 or 401 both mean Bitbucket is reachable; 5xx or timeout = degraded
            $ok = $response->status() < 500;
        } catch (\Throwable) {
            $ok = false;
        }

        return ['ok' => $ok, 'duration_ms' => $this->ms($start)];
    }

    private function checkAnthropic(): array
    {
        $start = hrtime(true);

        try {
            $response = Http::timeout(2)->get(config('ai.providers.anthropic.url'));
            $ok = $response->status() < 500;
        } catch (\Throwable) {
            $ok = false;
        }

        return ['ok' => $ok, 'duration_ms' => $this->ms($start)];
    }

    private function checkOpenAi(): array
    {
        $start = hrtime(true);

        try {
            $response = Http::timeout(2)->get('https://api.openai.com/v1/');
            $ok = $response->status() < 500;
        } catch (\Throwable) {
            $ok = false;
        }

        return ['ok' => $ok, 'duration_ms' => $this->ms($start)];
    }

    private function ms(int $startNs): int
    {
        return (int) ((hrtime(true) - $startNs) / 1_000_000);
    }

    private function resolveVersion(): string
    {
        $configured = config('cdv-rabbit.version');

        if ($configured) {
            return (string) $configured;
        }

        $output = shell_exec('git -C '.base_path().' rev-parse --short HEAD 2>/dev/null');

        return $output ? trim($output) : 'unknown';
    }
}
