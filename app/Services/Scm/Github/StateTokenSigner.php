<?php

declare(strict_types=1);

namespace App\Services\Scm\Github;

use Illuminate\Support\Facades\Cache;

/**
 * HMAC-signed state token for the GitHub App install OAuth-like flow.
 *
 * Payload carries `{w: workspace_id, exp: unix_ts, n: nonce}`. The HMAC key is
 * APP_KEY-derived. The nonce is single-use: stored in cache for the TTL and
 * forgotten on first successful verify, defeating replay attacks.
 */
class StateTokenSigner
{
    private const TTL_SECONDS = 600; // 10 minutes

    public function sign(int $workspaceId, ?int $now = null): string
    {
        $now ??= time();
        $nonce = bin2hex(random_bytes(16));
        $payload = json_encode([
            'w' => $workspaceId,
            'exp' => $now + self::TTL_SECONDS,
            'n' => $nonce,
        ]);

        $payloadB64 = $this->b64UrlEncode((string) $payload);
        $sig = hash_hmac('sha256', $payloadB64, $this->secret(), true);
        $sigB64 = $this->b64UrlEncode($sig);

        Cache::put($this->nonceKey($nonce), true, self::TTL_SECONDS);

        return "{$payloadB64}.{$sigB64}";
    }

    /** @return array{w: int, exp: int, n: string}|null */
    public function verify(string $token): ?array
    {
        if (! str_contains($token, '.')) {
            return null;
        }

        [$payloadB64, $sigB64] = explode('.', $token, 2);

        $expectedSig = hash_hmac('sha256', $payloadB64, $this->secret(), true);
        $providedSig = $this->b64UrlDecode($sigB64);

        if (! hash_equals($expectedSig, $providedSig)) {
            return null;
        }

        $payload = json_decode($this->b64UrlDecode($payloadB64), true);

        if (! is_array($payload) || ! isset($payload['w'], $payload['exp'], $payload['n'])) {
            return null;
        }

        if ((int) $payload['exp'] < time()) {
            return null;
        }

        $nonceKey = $this->nonceKey((string) $payload['n']);
        if (! Cache::has($nonceKey)) {
            return null;
        }

        // Single-use: consume the nonce.
        Cache::forget($nonceKey);

        return [
            'w' => (int) $payload['w'],
            'exp' => (int) $payload['exp'],
            'n' => (string) $payload['n'],
        ];
    }

    private function secret(): string
    {
        return hash('sha256', (string) config('app.key').':scm-github-install');
    }

    private function nonceKey(string $nonce): string
    {
        return "scm:gh:install:nonce:{$nonce}";
    }

    private function b64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function b64UrlDecode(string $data): string
    {
        $pad = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
