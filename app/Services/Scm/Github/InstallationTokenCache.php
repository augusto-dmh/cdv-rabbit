<?php

declare(strict_types=1);

namespace App\Services\Scm\Github;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Exchanges the App-level JWT for a per-installation access token and caches
 * it for slightly less than its natural lifetime (~60min on the GitHub side).
 */
class InstallationTokenCache
{
    private const CACHE_TTL_SECONDS = 3000; // 50min — GH tokens last 60min; refresh early to avoid edge expiry.

    public function __construct(
        private readonly JwtSigner $signer,
        private readonly string $baseUrl,
    ) {}

    public function tokenFor(string $installationId): string
    {
        if ($installationId === '') {
            throw new RuntimeException('GitHub installation_id is empty — workspace not connected.');
        }

        return Cache::remember(
            $this->cacheKey($installationId),
            self::CACHE_TTL_SECONDS,
            fn (): string => $this->mintNewToken($installationId),
        );
    }

    public function forget(string $installationId): void
    {
        Cache::forget($this->cacheKey($installationId));
    }

    private function cacheKey(string $installationId): string
    {
        return "scm:github:installation_token:{$installationId}";
    }

    private function mintNewToken(string $installationId): string
    {
        $jwt = $this->signer->mint();

        $response = Http::withToken($jwt)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->post("{$this->baseUrl}/app/installations/{$installationId}/access_tokens");

        if ($response->failed()) {
            throw new RuntimeException(
                "GitHub installation token exchange failed ({$response->status()}): "
                .substr($response->body(), 0, 200)
            );
        }

        $token = (string) ($response->json()['token'] ?? '');

        if ($token === '') {
            throw new RuntimeException('GitHub installation token response missing token field.');
        }

        return $token;
    }
}
