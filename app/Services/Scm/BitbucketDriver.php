<?php

declare(strict_types=1);

namespace App\Services\Scm;

use App\Models\Repository;
use App\Models\Workspace;
use App\Services\Scm\Contracts\ScmDriverInterface;
use App\Services\Scm\Dto\CommentHandle;
use App\Services\Scm\Dto\CredentialCheck;
use App\Services\Scm\Dto\FileChangeDto;
use App\Services\Scm\Dto\InlineCommentPayload;
use App\Services\Scm\Dto\PullRequestDto;
use App\Services\Scm\Dto\RepositoryDto;
use App\Services\Scm\Dto\WebhookHandle;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BitbucketDriver implements ScmDriverInterface
{
    private string $token;

    private ?string $serviceAccount;

    private string $workspaceSlug;

    private string $baseUrl;

    /** @var array<string, int|null>|null */
    private ?array $lastRateLimit = null;

    public function __construct(private readonly Workspace $workspace)
    {
        $this->token = (string) $workspace->bitbucket_token;
        $this->serviceAccount = $workspace->bitbucket_service_account;
        $this->workspaceSlug = (string) $workspace->scm_owner_slug;
        $this->baseUrl = rtrim((string) config('services.bitbucket.base_url', 'https://api.bitbucket.org/2.0'), '/');
    }

    public function verifyCredentials(): CredentialCheck
    {
        $response = $this->request('GET', '/user');
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('verifyCredentials', ['status' => $response->status()]);

        if ($response->failed()) {
            return new CredentialCheck(
                valid: false,
                identity: '',
                reason: "Bitbucket /user returned {$response->status()}: ".substr($response->body(), 0, 200),
            );
        }

        $body = $response->json() ?? [];
        $accountId = $body['account_id'] ?? null;

        if ($accountId === null) {
            return new CredentialCheck(
                valid: false,
                identity: '',
                reason: 'Token is invalid or missing required Bitbucket scopes.',
            );
        }

        return new CredentialCheck(
            valid: true,
            identity: 'bb-user:'.$accountId,
        );
    }

    public function listRepositories(): Collection
    {
        $results = [];
        $url = "/repositories/{$this->workspaceSlug}";

        do {
            $response = $this->request('GET', $url);
            $this->captureRateLimitHeaders($response);

            $body = $response->json() ?? [];
            $results = array_merge($results, $body['values'] ?? []);

            $url = isset($body['next']) ? str_replace($this->baseUrl, '', (string) $body['next']) : null;
        } while ($url !== null);

        Log::channel('bitbucket')->info('listRepositories', [
            'workspace' => $this->workspaceSlug,
            'count' => count($results),
        ]);

        return collect($results)->map(fn (array $r): RepositoryDto => $this->mapRepository($r));
    }

    public function getRepository(string $scmRepoId): ?RepositoryDto
    {
        $fullName = $this->resolveFullName($scmRepoId);
        $response = $this->request('GET', "/repositories/{$fullName}");
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('getRepository', [
            'full_name' => $fullName,
            'status' => $response->status(),
        ]);

        if ($response->status() === 404) {
            return null;
        }

        $body = $response->json();

        return $body ? $this->mapRepository($body) : null;
    }

    public function getPullRequest(string $scmRepoId, int $prNumber): ?PullRequestDto
    {
        $fullName = $this->resolveFullName($scmRepoId);
        $response = $this->request('GET', "/repositories/{$fullName}/pullrequests/{$prNumber}");
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('getPullRequest', [
            'full_name' => $fullName,
            'pr_number' => $prNumber,
            'status' => $response->status(),
        ]);

        if ($response->status() === 404) {
            return null;
        }

        $body = $response->json();

        return $body ? $this->mapPullRequest($body) : null;
    }

    public function getChangedFiles(string $scmRepoId, int $prNumber): Collection
    {
        $fullName = $this->resolveFullName($scmRepoId);
        $response = $this->request('GET', "/repositories/{$fullName}/pullrequests/{$prNumber}/diffstat");
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('getChangedFiles', [
            'full_name' => $fullName,
            'pr_number' => $prNumber,
            'status' => $response->status(),
        ]);

        if ($response->status() === 404) {
            return collect();
        }

        $body = $response->json() ?? [];

        return collect($body['values'] ?? [])->map(fn (array $f): FileChangeDto => new FileChangeDto(
            path: (string) ($f['new']['path'] ?? $f['old']['path'] ?? ''),
            status: (string) ($f['status'] ?? 'modified'),
            linesAdded: (int) ($f['lines_added'] ?? 0),
            linesRemoved: (int) ($f['lines_removed'] ?? 0),
        ));
    }

    public function getDiff(string $scmRepoId, int $prNumber): ?string
    {
        $fullName = $this->resolveFullName($scmRepoId);
        $response = $this->request('GET', "/repositories/{$fullName}/pullrequests/{$prNumber}/diff");
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('getDiff', [
            'full_name' => $fullName,
            'pr_number' => $prNumber,
            'status' => $response->status(),
        ]);

        if ($response->status() === 404) {
            return null;
        }

        return $response->body();
    }

    public function postPullRequestComment(string $scmRepoId, int $prNumber, string $body): CommentHandle
    {
        $fullName = $this->resolveFullName($scmRepoId);
        $response = $this->request('POST', "/repositories/{$fullName}/pullrequests/{$prNumber}/comments", [
            'content' => ['raw' => $body],
        ]);
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('postPullRequestComment', [
            'full_name' => $fullName,
            'pr_number' => $prNumber,
            'status' => $response->status(),
        ]);

        $json = $this->jsonOrFail($response, 'postPullRequestComment');

        return new CommentHandle(scmCommentId: (string) ($json['id'] ?? ''), providerSpecific: $json);
    }

    public function postInlineComment(string $scmRepoId, int $prNumber, InlineCommentPayload $payload): CommentHandle
    {
        $fullName = $this->resolveFullName($scmRepoId);
        $response = $this->request('POST', "/repositories/{$fullName}/pullrequests/{$prNumber}/comments", [
            'content' => ['raw' => $payload->body],
            'inline' => [
                'path' => $payload->path,
                'to' => $payload->line,
            ],
        ]);
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('postInlineComment', [
            'full_name' => $fullName,
            'pr_number' => $prNumber,
            'path' => $payload->path,
            'line' => $payload->line,
            'status' => $response->status(),
        ]);

        $json = $this->jsonOrFail($response, 'postInlineComment');

        return new CommentHandle(scmCommentId: (string) ($json['id'] ?? ''), providerSpecific: $json);
    }

    public function updateComment(string $scmRepoId, int $prNumber, CommentHandle $handle, string $body): CommentHandle
    {
        $fullName = $this->resolveFullName($scmRepoId);
        $response = $this->request('PUT', "/repositories/{$fullName}/pullrequests/{$prNumber}/comments/{$handle->scmCommentId}", [
            'content' => ['raw' => $body],
        ]);
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('updateComment', [
            'full_name' => $fullName,
            'pr_number' => $prNumber,
            'comment_id' => $handle->scmCommentId,
            'status' => $response->status(),
        ]);

        $json = $this->jsonOrFail($response, 'updateComment');

        return new CommentHandle(scmCommentId: $handle->scmCommentId, providerSpecific: $json);
    }

    public function registerWebhook(string $scmRepoId, string $callbackUrl, string $secret): ?WebhookHandle
    {
        $fullName = $this->resolveFullName($scmRepoId);
        $response = $this->request('POST', "/repositories/{$fullName}/hooks", [
            'description' => 'cdv-rabbit webhook',
            'url' => $callbackUrl,
            'secret' => $secret,
            'active' => true,
            'events' => ['pullrequest:created'],
        ]);
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('registerWebhook', [
            'full_name' => $fullName,
            'events' => ['pullrequest:created'],
            'status' => $response->status(),
        ]);

        $json = $this->jsonOrFail($response, 'registerWebhook');

        return new WebhookHandle(scmWebhookUuid: isset($json['uuid']) ? (string) $json['uuid'] : null);
    }

    public function deleteWebhook(string $scmRepoId, ?WebhookHandle $handle): void
    {
        if ($handle === null || $handle->scmWebhookUuid === null) {
            return;
        }

        $fullName = $this->resolveFullName($scmRepoId);
        $response = $this->request('DELETE', "/repositories/{$fullName}/hooks/{$handle->scmWebhookUuid}");
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('deleteWebhook', [
            'full_name' => $fullName,
            'scm_webhook_uuid' => $handle->scmWebhookUuid,
            'status' => $response->status(),
        ]);
    }

    /**
     * AC51: post a Bitbucket Cloud "build status" on the PR head SHA so consumer
     * repos can gate auto-merge on cdv-rabbit's verdict. Maps internal states
     * ('pending'|'success'|'failure') onto Bitbucket's build-status states
     * (INPROGRESS|SUCCESSFUL|FAILED).
     */
    public function postCommitStatus(
        string $scmRepoId,
        string $headSha,
        string $state,
        string $context,
        string $description,
        ?string $targetUrl = null,
    ): void {
        $fullName = $this->resolveFullName($scmRepoId);

        $bbState = match ($state) {
            'success' => 'SUCCESSFUL',
            'failure' => 'FAILED',
            default => 'INPROGRESS',
        };

        $payload = [
            'key' => $context,
            'state' => $bbState,
            'name' => $context,
            'description' => substr($description, 0, 140),
            'url' => $targetUrl ?? config('app.url', 'https://cdv-rabbit'),
        ];

        $response = $this->request(
            'POST',
            "/repositories/{$fullName}/commit/{$headSha}/statuses/build",
            $payload,
        );
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('postCommitStatus', [
            'full_name' => $fullName,
            'head_sha' => substr($headSha, 0, 8),
            'state' => $bbState,
            'context' => $context,
            'status' => $response->status(),
        ]);
    }

    /** @return array<string, int|null>|null */
    public function lastRateLimit(): ?array
    {
        return $this->lastRateLimit;
    }

    /**
     * Resolve scmRepoId to BB full_name for URL construction. Falls back to scmRepoId
     * itself when no matching Repository row exists yet (e.g. during initial sync).
     */
    private function resolveFullName(string $scmRepoId): string
    {
        $repo = Repository::where('scm_repo_id', $scmRepoId)->first();

        return $repo?->full_name ?? $scmRepoId;
    }

    /** @param  array<string, mixed>  $r */
    private function mapRepository(array $r): RepositoryDto
    {
        return new RepositoryDto(
            scmRepoId: (string) ($r['uuid'] ?? ''),
            ownerSlug: $this->workspaceSlug,
            name: (string) ($r['name'] ?? $r['slug'] ?? ''),
            fullName: (string) ($r['full_name'] ?? ''),
            defaultBranch: (string) ($r['mainbranch']['name'] ?? 'main'),
            isPrivate: (bool) ($r['is_private'] ?? true),
        );
    }

    /** @param  array<string, mixed>  $pr */
    private function mapPullRequest(array $pr): PullRequestDto
    {
        return new PullRequestDto(
            number: (int) ($pr['id'] ?? 0),
            title: (string) ($pr['title'] ?? ''),
            state: (string) ($pr['state'] ?? ''),
            sourceBranch: (string) ($pr['source']['branch']['name'] ?? ''),
            targetBranch: (string) ($pr['destination']['branch']['name'] ?? ''),
            headSha: (string) ($pr['source']['commit']['hash'] ?? ''),
            baseSha: (string) ($pr['destination']['commit']['hash'] ?? ''),
            authorLogin: (string) ($pr['author']['display_name'] ?? ''),
        );
    }

    /** @param  array<string, mixed>  $json */
    private function request(string $method, string $path, array $json = []): Response
    {
        $pending = Http::withBasicAuth((string) $this->serviceAccount, $this->token)
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
            'PUT' => $pending->put($url, $json),
            'DELETE' => $pending->delete($url),
            default => $pending->get($url),
        };
    }

    /** @return array<string, mixed> */
    private function jsonOrFail(Response $response, string $method): array
    {
        if ($response->failed()) {
            throw new RuntimeException(
                "Bitbucket {$method} failed ({$response->status()}): {$response->body()}"
            );
        }

        return $response->json() ?? [];
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
