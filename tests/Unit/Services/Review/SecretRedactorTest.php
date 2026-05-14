<?php

use App\Services\Review\SecretRedactor;

beforeEach(function () {
    $this->redactor = new SecretRedactor;
});

dataset('aws_access_key_positives', [
    'standalone key' => ['AKIAIOSFODNN7EXAMPLE'],
    'in assignment' => ['aws_access_key_id=AKIAIOSFODNN7EXAMPLE'],
    'with quotes' => ['"access_key": "AKIAIOSFODNN7EXAMPLE"'],
]);

dataset('jwt_positives', [
    'typical jwt' => ['eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c'],
]);

dataset('private_key_positives', [
    'rsa key' => ["-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA1234\n-----END RSA PRIVATE KEY-----"],
    'ec key' => ["-----BEGIN EC PRIVATE KEY-----\nABCDEF\n-----END EC PRIVATE KEY-----"],
    'openssh key' => ["-----BEGIN OPENSSH PRIVATE KEY-----\nABCDEF\n-----END OPENSSH PRIVATE KEY-----"],
    'generic private key' => ["-----BEGIN PRIVATE KEY-----\nABCDEF\n-----END PRIVATE KEY-----"],
]);

it('redacts AWS access key IDs', function (string $input) {
    $result = $this->redactor->redact($input);

    expect($result->count)->toBeGreaterThan(0)
        ->and($result->sanitized)->toContain('<<SECRET_REDACTED>>')
        ->and($result->matchedPatterns)->toContain('aws_access_key_id');
})->with('aws_access_key_positives');

it('redacts JWT tokens', function (string $input) {
    $result = $this->redactor->redact($input);

    expect($result->count)->toBeGreaterThan(0)
        ->and($result->sanitized)->toContain('<<SECRET_REDACTED>>')
        ->and($result->matchedPatterns)->toContain('jwt_token');
})->with('jwt_positives');

it('redacts PEM private keys', function (string $input) {
    $result = $this->redactor->redact($input);

    expect($result->count)->toBeGreaterThan(0)
        ->and($result->sanitized)->toContain('<<SECRET_REDACTED>>')
        ->and($result->matchedPatterns)->toContain('pem_private_key');
})->with('private_key_positives');

it('redacts AWS secret access key in context', function () {
    $input = 'aws_secret_access_key = wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY12';

    $result = $this->redactor->redact($input);

    expect($result->count)->toBeGreaterThan(0)
        ->and($result->matchedPatterns)->toContain('aws_secret_access_key');
});

it('does not redact clean diff with no secrets', function () {
    $input = "diff --git a/app/Foo.php b/app/Foo.php\n+class Foo {}";

    $result = $this->redactor->redact($input);

    expect($result->count)->toBe(0)
        ->and($result->matchedPatterns)->toBeEmpty()
        ->and($result->sanitized)->toBe($input);
});

it('returns correct count for multiple secrets', function () {
    $input = "AKIAIOSFODNN7EXAMPLE\nAKIAIOSFODNN7EXAMPL2";

    $result = $this->redactor->redact($input);

    expect($result->count)->toBe(2);
});
