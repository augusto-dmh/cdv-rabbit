<?php

use App\Support\AnthropicErrorClassifier;
use App\Support\RetryDecision;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\RequestException as LaravelRequestException;
use Illuminate\Http\Client\Response as LaravelResponse;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

function makeGuzzleException(int $status): GuzzleRequestException
{
    $request = new GuzzleRequest('POST', 'https://api.anthropic.com/v1/messages');
    $response = new GuzzleResponse($status);

    return new GuzzleRequestException('Error', $request, $response);
}

function makeLaravelRequestException(int $status): LaravelRequestException
{
    $psrResponse = new GuzzleResponse($status, [], json_encode(['error' => ['type' => 'test', 'message' => 'test']]));
    $laravelResponse = new LaravelResponse(new Response($status));

    return new LaravelRequestException(new LaravelResponse($psrResponse));
}

dataset('terminal_http_codes', [
    'HTTP 400' => [400],
    'HTTP 403' => [403],
    'HTTP 404' => [404],
    'HTTP 413' => [413],
]);

dataset('pause_workspace_http_codes', [
    'HTTP 401' => [401],
    'HTTP 402' => [402],
]);

dataset('retry_with_backoff_http_codes', [
    'HTTP 429' => [429],
    'HTTP 500' => [500],
    'HTTP 503' => [503],
    'HTTP 529' => [529],
]);

beforeEach(function () {
    $this->classifier = new AnthropicErrorClassifier;
});

it('classifies laravel/ai RateLimitedException as RetryWithBackoff', function () {
    $e = RateLimitedException::forProvider('anthropic');

    expect($this->classifier->classify($e))->toBe(RetryDecision::RetryWithBackoff);
});

it('classifies laravel/ai ProviderOverloadedException as RetryWithBackoff', function () {
    $e = ProviderOverloadedException::forProvider('anthropic');

    expect($this->classifier->classify($e))->toBe(RetryDecision::RetryWithBackoff);
});

it('classifies laravel/ai InsufficientCreditsException as PauseWorkspace', function () {
    $e = InsufficientCreditsException::forProvider('anthropic');

    expect($this->classifier->classify($e))->toBe(RetryDecision::PauseWorkspace);
});

it('classifies unknown exceptions without HTTP status as Terminal', function () {
    $e = new RuntimeException('Unknown error');

    expect($this->classifier->classify($e))->toBe(RetryDecision::Terminal);
});

it('classifies Guzzle terminal HTTP codes as Terminal', function (int $status) {
    $e = makeGuzzleException($status);

    expect($this->classifier->classify($e))->toBe(RetryDecision::Terminal);
})->with('terminal_http_codes');

it('classifies Guzzle pause-workspace HTTP codes as PauseWorkspace', function (int $status) {
    $e = makeGuzzleException($status);

    expect($this->classifier->classify($e))->toBe(RetryDecision::PauseWorkspace);
})->with('pause_workspace_http_codes');

it('classifies Guzzle retry HTTP codes as RetryWithBackoff', function (int $status) {
    $e = makeGuzzleException($status);

    expect($this->classifier->classify($e))->toBe(RetryDecision::RetryWithBackoff);
})->with('retry_with_backoff_http_codes');

it('traverses previous exception chain to find Guzzle response status', function () {
    $guzzleException = makeGuzzleException(429);
    $wrappedException = new RuntimeException('Wrapped', 0, $guzzleException);

    expect($this->classifier->classify($wrappedException))->toBe(RetryDecision::RetryWithBackoff);
});

it('classifies 5xx above 529 as RetryWithBackoff', function () {
    $e = makeGuzzleException(502);

    expect($this->classifier->classify($e))->toBe(RetryDecision::RetryWithBackoff);
});
