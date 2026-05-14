<?php

namespace App\Services\Review;

/**
 * Pre-LLM regex pass that replaces known secret patterns with <<SECRET_REDACTED>>.
 *
 * High-entropy string detection is intentionally omitted — the false-positive
 * rate on source code (base64 assets, hashes, encoded configs) is too high for
 * an automated pre-pass. Future work: entropy scanner as opt-in per workspace.
 */
final class SecretRedactor
{
    private const REDACTION_PLACEHOLDER = '<<SECRET_REDACTED>>';

    /** @var array<string, non-empty-string> Pattern name => PCRE regex */
    private const PATTERNS = [
        'aws_access_key_id' => '/\bAKIA[0-9A-Z]{16}\b/',
        'aws_secret_access_key' => '/(?:aws_secret_access_key|AWS_SECRET_ACCESS_KEY)\s*[=:]\s*["\']?([A-Za-z0-9\/+]{40})["\']?/',
        'google_private_key' => '/"private_key"\s*:\s*"(-----BEGIN [A-Z ]+ PRIVATE KEY-----[^"]+-----END [A-Z ]+ PRIVATE KEY-----[^"]*)"/',
        'pem_private_key' => '/-----BEGIN (?:RSA |OPENSSH |EC |DSA )?PRIVATE KEY-----[\s\S]+?-----END (?:RSA |OPENSSH |EC |DSA )?PRIVATE KEY-----/',
        'jwt_token' => '/eyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/',
    ];

    public function redact(string $diff): RedactionResult
    {
        $sanitized = $diff;
        $totalCount = 0;
        $matchedPatterns = [];

        foreach (self::PATTERNS as $name => $pattern) {
            $count = 0;
            $sanitized = preg_replace($pattern, self::REDACTION_PLACEHOLDER, $sanitized, -1, $count);
            if ($count > 0) {
                $totalCount += $count;
                $matchedPatterns[] = $name;
            }
        }

        return new RedactionResult(
            sanitized: $sanitized,
            count: $totalCount,
            matchedPatterns: $matchedPatterns,
        );
    }
}
