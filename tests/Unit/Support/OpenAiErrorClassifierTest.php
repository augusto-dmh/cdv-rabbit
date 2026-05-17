<?php

use App\Support\OpenAiErrorClassifier;
use App\Support\RetryDecision;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\RequestException as LaravelRequestException;
use Illuminate\Http\Client\Response as LaravelResponse;
use Laravel\Ai\Exceptions\RateLimitedException;

/**
 * Build a LaravelRequestException carrying a JSON body and HTTP status.
 *
 * @param  array<string, mixed>  $body
 */
function makeOpenAiLaravelRequestException(int $status, array $body = []): LaravelRequestException
{
    $psrResponse = new GuzzleResponse($status, ['Content-Type' => 'application/json'], json_encode($body));

    return new LaravelRequestException(new LaravelResponse($psrResponse));
}

function makeOpenAiGuzzleException(int $status): GuzzleRequestException
{
    $request = new GuzzleRequest('POST', 'https://api.openai.com/v1/chat/completions');
    $response = new GuzzleResponse($status);

    return new GuzzleRequestException('Error', $request, $response);
}

dataset('openai_terminal_http_codes', [
    'HTTP 400' => [400],
    'HTTP 403' => [403],
    'HTTP 404' => [404],
]);

dataset('openai_retry_http_codes', [
    'HTTP 429' => [429],
    'HTTP 500' => [500],
    'HTTP 503' => [503],
]);

beforeEach(function () {
    $this->classifier = new OpenAiErrorClassifier;
});

// --- Direct LaravelRequestException (non-SDK path) ---

it('classifies direct Laravel 429 rate_limit_exceeded as RetryWithBackoff', function () {
    $e = makeOpenAiLaravelRequestException(429, ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'rate limit']]);

    expect($this->classifier->classify($e))->toBe(RetryDecision::RetryWithBackoff);
});

it('classifies direct Laravel 429 insufficient_quota as Terminal', function () {
    $e = makeOpenAiLaravelRequestException(429, ['error' => ['code' => 'insufficient_quota', 'message' => 'quota exceeded']]);

    expect($this->classifier->classify($e))->toBe(RetryDecision::Terminal);
});

it('classifies direct Laravel 400 context_length_exceeded as Terminal', function () {
    $e = makeOpenAiLaravelRequestException(400, ['error' => ['code' => 'context_length_exceeded', 'message' => 'too long']]);

    expect($this->classifier->classify($e))->toBe(RetryDecision::Terminal);
});

it('classifies direct Laravel terminal HTTP codes as Terminal', function (int $status) {
    $e = makeOpenAiLaravelRequestException($status, ['error' => ['type' => 'unknown_error']]);

    expect($this->classifier->classify($e))->toBe(RetryDecision::Terminal);
})->with('openai_terminal_http_codes');

it('classifies direct Laravel retry HTTP codes as RetryWithBackoff', function (int $status) {
    $e = makeOpenAiLaravelRequestException($status);

    expect($this->classifier->classify($e))->toBe(RetryDecision::RetryWithBackoff);
})->with('openai_retry_http_codes');

// --- SDK-wrapped RateLimitedException path ---

it('classifies SDK RateLimitedException with prev 429 body rate_limit_exceeded as RetryWithBackoff', function () {
    $prev = makeOpenAiLaravelRequestException(429, ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'rate limit']]);
    $e = RateLimitedException::forProvider('openai', 0, $prev);

    expect($this->classifier->classify($e))->toBe(RetryDecision::RetryWithBackoff);
});

it('classifies SDK RateLimitedException with prev 429 body insufficient_quota as Terminal', function () {
    $prev = makeOpenAiLaravelRequestException(429, ['error' => ['code' => 'insufficient_quota', 'message' => 'quota exceeded']]);
    $e = RateLimitedException::forProvider('openai', 0, $prev);

    expect($this->classifier->classify($e))->toBe(RetryDecision::Terminal);
});

it('classifies SDK RateLimitedException with no prev chain as RetryWithBackoff', function () {
    // No previous exception — the SDK-wrapped case where we cannot read error.code.
    // We cannot confirm 429 either, so the classifier must conservatively treat it
    // as recoverable (was incorrectly Terminal before the fix).
    $e = RateLimitedException::forProvider('openai');

    expect($this->classifier->classify($e))->toBe(RetryDecision::RetryWithBackoff);
});

it('classifies SDK RateLimitedException with prev 429 but no error body as RetryWithBackoff', function () {
    $prev = makeOpenAiLaravelRequestException(429);
    $e = RateLimitedException::forProvider('openai', 0, $prev);

    expect($this->classifier->classify($e))->toBe(RetryDecision::RetryWithBackoff);
});

// --- Guzzle exception path ---

it('classifies Guzzle terminal HTTP codes as Terminal', function (int $status) {
    $e = makeOpenAiGuzzleException($status);

    expect($this->classifier->classify($e))->toBe(RetryDecision::Terminal);
})->with('openai_terminal_http_codes');

it('classifies Guzzle retry HTTP codes as RetryWithBackoff', function (int $status) {
    $e = makeOpenAiGuzzleException($status);

    expect($this->classifier->classify($e))->toBe(RetryDecision::RetryWithBackoff);
})->with('openai_retry_http_codes');

it('traverses previous exception chain to find Guzzle response status', function () {
    $guzzle = makeOpenAiGuzzleException(429);
    $wrapped = new RuntimeException('Wrapped', 0, $guzzle);

    expect($this->classifier->classify($wrapped))->toBe(RetryDecision::RetryWithBackoff);
});

// --- Truly unknown ---

it('classifies unknown exceptions without HTTP status as Terminal', function () {
    $e = new RuntimeException('Unknown error');

    expect($this->classifier->classify($e))->toBe(RetryDecision::Terminal);
});
