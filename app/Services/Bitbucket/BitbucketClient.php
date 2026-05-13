<?php

declare(strict_types=1);

namespace App\Services\Bitbucket;

use App\Models\Workspace;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BitbucketClient
{
    private string $token;

    private string $workspaceSlug;

    private string $baseUrl;

    private ?array $lastRateLimit = null;

    public function __construct(Workspace $workspace)
    {
        $this->token = $workspace->bitbucket_token;
        $this->workspaceSlug = $workspace->bitbucket_workspace_slug;
        $this->baseUrl = rtrim((string) config('services.bitbucket.base_url', 'https://api.bitbucket.org/2.0'), '/');
    }

    public function me(): array
    {
        $response = $this->request('GET', '/user');

        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('me', ['status' => $response->status()]);

        return $response->json();
    }

    public function listRepositories(): array
    {
        $results = [];
        $url = "/repositories/{$this->workspaceSlug}";

        do {
            $response = $this->request('GET', $url);
            $this->captureRateLimitHeaders($response);

            $body = $response->json();
            $results = array_merge($results, $body['values'] ?? []);

            $url = isset($body['next']) ? str_replace($this->baseUrl, '', $body['next']) : null;
        } while ($url !== null);

        Log::channel('bitbucket')->info('listRepositories', [
            'workspace' => $this->workspaceSlug,
            'count' => count($results),
        ]);

        return $results;
    }

    public function getRepository(string $fullSlug): ?array
    {
        $response = $this->request('GET', "/repositories/{$fullSlug}");
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('getRepository', [
            'full_slug' => $fullSlug,
            'status' => $response->status(),
        ]);

        if ($response->status() === 404) {
            return null;
        }

        return $response->json();
    }

    public function getPullRequest(string $fullSlug, int $prNumber): ?array
    {
        $response = $this->request('GET', "/repositories/{$fullSlug}/pullrequests/{$prNumber}");
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('getPullRequest', [
            'full_slug' => $fullSlug,
            'pr_number' => $prNumber,
            'status' => $response->status(),
        ]);

        if ($response->status() === 404) {
            return null;
        }

        return $response->json();
    }

    public function getDiffStat(string $fullSlug, int $prNumber): ?array
    {
        $response = $this->request('GET', "/repositories/{$fullSlug}/pullrequests/{$prNumber}/diffstat");
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('getDiffStat', [
            'full_slug' => $fullSlug,
            'pr_number' => $prNumber,
            'status' => $response->status(),
        ]);

        if ($response->status() === 404) {
            return null;
        }

        return $response->json();
    }

    public function getDiff(string $fullSlug, int $prNumber): ?string
    {
        $response = $this->request('GET', "/repositories/{$fullSlug}/pullrequests/{$prNumber}/diff");
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('getDiff', [
            'full_slug' => $fullSlug,
            'pr_number' => $prNumber,
            'status' => $response->status(),
        ]);

        if ($response->status() === 404) {
            return null;
        }

        return $response->body();
    }

    public function postPullRequestComment(string $fullSlug, int $prNumber, string $content): array
    {
        $response = $this->request('POST', "/repositories/{$fullSlug}/pullrequests/{$prNumber}/comments", [
            'content' => ['raw' => $content],
        ]);
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('postPullRequestComment', [
            'full_slug' => $fullSlug,
            'pr_number' => $prNumber,
            'status' => $response->status(),
        ]);

        return $response->json();
    }

    public function postInlineComment(string $fullSlug, int $prNumber, string $content, string $path, int $line): array
    {
        $response = $this->request('POST', "/repositories/{$fullSlug}/pullrequests/{$prNumber}/comments", [
            'content' => ['raw' => $content],
            'inline' => [
                'path' => $path,
                'to' => $line,
            ],
        ]);
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('postInlineComment', [
            'full_slug' => $fullSlug,
            'pr_number' => $prNumber,
            'path' => $path,
            'line' => $line,
            'status' => $response->status(),
        ]);

        return $response->json();
    }

    public function registerWebhook(string $fullSlug, string $url, string $secret, array $events): array
    {
        $response = $this->request('POST', "/repositories/{$fullSlug}/hooks", [
            'description' => 'cdv-rabbit webhook',
            'url' => $url,
            'secret' => $secret,
            'active' => true,
            'events' => $events,
        ]);
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('registerWebhook', [
            'full_slug' => $fullSlug,
            'events' => $events,
            'status' => $response->status(),
        ]);

        return $response->json();
    }

    public function deleteWebhook(string $fullSlug, string $webhookUuid): bool
    {
        $response = $this->request('DELETE', "/repositories/{$fullSlug}/hooks/{$webhookUuid}");
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('deleteWebhook', [
            'full_slug' => $fullSlug,
            'webhook_uuid' => $webhookUuid,
            'status' => $response->status(),
        ]);

        return $response->successful();
    }

    public function lastRateLimit(): ?array
    {
        return $this->lastRateLimit;
    }

    private function request(string $method, string $path, array $json = []): Response
    {
        $pending = Http::withToken($this->token)
            ->timeout(30)
            ->retry(3, 0, function (\Exception $exception, PendingRequest $request): bool {
                if (! $exception instanceof RequestException) {
                    return false;
                }

                $response = $exception->response;

                if ($response->status() === 429) {
                    $retryAfter = (int) ($response->header('Retry-After') ?: 1);
                    sleep($retryAfter);

                    return true;
                }

                return $response->serverError();
            }, throw: false);

        $url = $this->baseUrl.$path;

        return match (strtoupper($method)) {
            'POST' => $pending->post($url, $json),
            'DELETE' => $pending->delete($url),
            default => $pending->get($url),
        };
    }

    private function captureRateLimitHeaders(Response $response): void
    {
        $remaining = $response->header('X-RateLimit-Remaining');
        $limit = $response->header('X-RateLimit-Limit');

        if ($remaining !== '' || $limit !== '') {
            $this->lastRateLimit = [
                'remaining' => $remaining !== '' ? (int) $remaining : null,
                'limit' => $limit !== '' ? (int) $limit : null,
            ];
        }
    }
}
