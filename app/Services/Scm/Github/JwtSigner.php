<?php

declare(strict_types=1);

namespace App\Services\Scm\Github;

use RuntimeException;

/**
 * Mints the App-level JWT GitHub requires before exchanging it for an
 * installation access token. Uses RS256 with PHP's native openssl_sign — no
 * external JWT library dependency.
 */
class JwtSigner
{
    public function __construct(
        private readonly string $appId,
        private readonly string $privateKey,
        private readonly int $ttlSeconds = 600,
    ) {}

    public function mint(?int $now = null): string
    {
        $now ??= time();

        if ($this->appId === '') {
            throw new RuntimeException('GITHUB_APP_ID is not configured.');
        }

        if ($this->privateKey === '') {
            throw new RuntimeException('GITHUB_APP_PRIVATE_KEY is not configured.');
        }

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $payload = [
            // GitHub recommends 60s clock-skew tolerance on the iat side.
            'iat' => $now - 60,
            'exp' => $now + $this->ttlSeconds,
            'iss' => $this->appId,
        ];

        $headerB64 = $this->base64UrlEncode((string) json_encode($header));
        $payloadB64 = $this->base64UrlEncode((string) json_encode($payload));
        $signingInput = "{$headerB64}.{$payloadB64}";

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        if (! $ok || $signature === '') {
            throw new RuntimeException('Failed to sign GitHub App JWT — check GITHUB_APP_PRIVATE_KEY.');
        }

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
