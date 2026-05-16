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
use App\Services\Scm\Github\InstallationTokenCache;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GithubDriver implements ScmDriverInterface
{
    private string $installationId;

    private string $baseUrl;

    /** @var array<string, int|string|null>|null */
    private ?array $lastRateLimit = null;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly InstallationTokenCache $tokenCache,
    ) {
        $this->installationId = (string) ($workspace->github_installation_id ?? '');
        $this->baseUrl = rtrim((string) config('services.github.base_url', 'https://api.github.com'), '/');
    }

    public function verifyCredentials(): CredentialCheck
    {
        try {
            $response = $this->request('GET', '/installation/repositories?per_page=1');
        } catch (RuntimeException $e) {
            return new CredentialCheck(
                valid: false,
                identity: '',
                reason: $e->getMessage(),
            );
        }

        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('github.verifyCredentials', ['status' => $response->status()]);

        if ($response->failed()) {
            return new CredentialCheck(
                valid: false,
                identity: '',
                reason: "GitHub installation check returned {$response->status()}: ".substr($response->body(), 0, 200),
            );
        }

        return new CredentialCheck(
            valid: true,
            identity: "gh-installation:{$this->installationId}",
        );
    }

    public function listRepositories(): Collection
    {
        $results = [];
        $url = '/installation/repositories?per_page=100';

        while ($url !== null) {
            $response = $this->request('GET', $url);
            $this->captureRateLimitHeaders($response);

            $body = $response->json() ?? [];
            $results = array_merge($results, $body['repositories'] ?? []);

            $url = $this->nextPageFromLinkHeader($response);
        }

        Log::channel('bitbucket')->info('github.listRepositories', [
            'installation_id' => $this->installationId,
            'count' => count($results),
        ]);

        return collect($results)->map(fn (array $r): RepositoryDto => $this->mapRepository($r));
    }

    public function getRepository(string $scmRepoId): ?RepositoryDto
    {
        $response = $this->request('GET', "/repositories/{$scmRepoId}");
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('github.getRepository', [
            'scm_repo_id' => $scmRepoId,
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
        $owner = $this->workspace->scm_owner_slug ?? '';
        $repoName = $this->resolveRepoName($scmRepoId);
        $response = $this->request('GET', "/repos/{$owner}/{$repoName}/pulls/{$prNumber}");
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('github.getPullRequest', [
            'scm_repo_id' => $scmRepoId,
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
        $owner = $this->workspace->scm_owner_slug ?? '';
        $repoName = $this->resolveRepoName($scmRepoId);
        $results = [];
        $url = "/repos/{$owner}/{$repoName}/pulls/{$prNumber}/files?per_page=100";

        while ($url !== null) {
            $response = $this->request('GET', $url);
            $this->captureRateLimitHeaders($response);

            if ($response->status() === 404) {
                return collect();
            }

            $body = $response->json() ?? [];
            $results = array_merge($results, is_array($body) ? $body : []);

            $url = $this->nextPageFromLinkHeader($response);
        }

        Log::channel('bitbucket')->info('github.getChangedFiles', [
            'scm_repo_id' => $scmRepoId,
            'pr_number' => $prNumber,
            'count' => count($results),
        ]);

        return collect($results)->map(fn (array $f): FileChangeDto => new FileChangeDto(
            path: (string) ($f['filename'] ?? ''),
            status: (string) ($f['status'] ?? 'modified'),
            linesAdded: (int) ($f['additions'] ?? 0),
            linesRemoved: (int) ($f['deletions'] ?? 0),
        ));
    }

    public function getDiff(string $scmRepoId, int $prNumber): ?string
    {
        $owner = $this->workspace->scm_owner_slug ?? '';
        $repoName = $this->resolveRepoName($scmRepoId);
        $response = $this->request('GET', "/repos/{$owner}/{$repoName}/pulls/{$prNumber}", accept: 'application/vnd.github.v3.diff');
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('github.getDiff', [
            'scm_repo_id' => $scmRepoId,
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
        $owner = $this->workspace->scm_owner_slug ?? '';
        $repoName = $this->resolveRepoName($scmRepoId);

        $response = $this->request('POST', "/repos/{$owner}/{$repoName}/issues/{$prNumber}/comments", [
            'body' => $body,
        ]);
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('github.postPullRequestComment', [
            'scm_repo_id' => $scmRepoId,
            'pr_number' => $prNumber,
            'status' => $response->status(),
        ]);

        $json = $this->jsonOrFail($response, 'postPullRequestComment');

        return new CommentHandle(scmCommentId: (string) ($json['id'] ?? ''), providerSpecific: $json);
    }

    public function postInlineComment(string $scmRepoId, int $prNumber, InlineCommentPayload $payload): CommentHandle
    {
        $owner = $this->workspace->scm_owner_slug ?? '';
        $repoName = $this->resolveRepoName($scmRepoId);

        $response = $this->request('POST', "/repos/{$owner}/{$repoName}/pulls/{$prNumber}/comments", [
            'body' => $payload->body,
            'commit_id' => $payload->headSha,
            'path' => $payload->path,
            'line' => $payload->line,
            'side' => 'RIGHT',
        ]);
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('github.postInlineComment', [
            'scm_repo_id' => $scmRepoId,
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
        $owner = $this->workspace->scm_owner_slug ?? '';
        $repoName = $this->resolveRepoName($scmRepoId);

        // GitHub PR review comments are updated via /pulls/comments/{cid} regardless of PR number.
        $response = $this->request('PATCH', "/repos/{$owner}/{$repoName}/pulls/comments/{$handle->scmCommentId}", [
            'body' => $body,
        ]);
        $this->captureRateLimitHeaders($response);

        Log::channel('bitbucket')->info('github.updateComment', [
            'scm_repo_id' => $scmRepoId,
            'pr_number' => $prNumber,
            'comment_id' => $handle->scmCommentId,
            'status' => $response->status(),
        ]);

        $json = $this->jsonOrFail($response, 'updateComment');

        return new CommentHandle(scmCommentId: $handle->scmCommentId, providerSpecific: $json);
    }

    /**
     * GitHub Apps deliver webhooks to the App's single configured URL — there is no
     * per-repository registration step. The driver intentionally returns null.
     */
    public function registerWebhook(string $scmRepoId, string $callbackUrl, string $secret): ?WebhookHandle
    {
        return null;
    }

    /**
     * GitHub Apps don't expose per-repository webhooks to remove, so this is a no-op.
     */
    public function deleteWebhook(string $scmRepoId, ?WebhookHandle $handle): void {}

    /** @return array<string, int|string|null>|null */
    public function lastRateLimit(): ?array
    {
        return $this->lastRateLimit;
    }

    /** @param  array<string, mixed>  $json */
    private function request(string $method, string $path, array $json = [], string $accept = 'application/vnd.github+json'): Response
    {
        $token = $this->tokenCache->tokenFor($this->installationId);

        $pending = Http::withToken($token)
            ->withHeaders([
                'Accept' => $accept,
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->timeout(30)
            ->retry(3, 0, function (\Exception $exception, PendingRequest $request): bool {
                if (! $exception instanceof RequestException) {
                    return false;
                }

                $response = $exception->response;

                if ($response->status() === 429 || $response->status() === 403) {
                    $retryAfter = (int) ($response->header('Retry-After') ?: 1);
                    sleep(min($retryAfter, 60));

                    return true;
                }

                return $response->serverError();
            }, throw: false);

        $url = $this->baseUrl.(str_starts_with($path, 'http') ? '' : $path);

        return match (strtoupper($method)) {
            'POST' => $pending->post($url, $json),
            'PATCH' => $pending->patch($url, $json),
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
                "GitHub {$method} failed ({$response->status()}): {$response->body()}"
            );
        }

        return $response->json() ?? [];
    }

    private function captureRateLimitHeaders(Response $response): void
    {
        $remaining = $response->header('X-RateLimit-Remaining');
        $limit = $response->header('X-RateLimit-Limit');
        $reset = $response->header('X-RateLimit-Reset');

        if ($remaining !== '' || $limit !== '' || $reset !== '') {
            $this->lastRateLimit = [
                'remaining' => $remaining !== '' ? (int) $remaining : null,
                'limit' => $limit !== '' ? (int) $limit : null,
                'reset' => $reset !== '' ? (int) $reset : null,
            ];
        }
    }

    /**
     * Parse the GitHub Link header for a rel="next" URL. Returns null when no next page.
     */
    private function nextPageFromLinkHeader(Response $response): ?string
    {
        $link = $response->header('Link');

        if ($link === '') {
            return null;
        }

        foreach (explode(',', $link) as $part) {
            if (preg_match('/<([^>]+)>;\s*rel="next"/', trim($part), $m) === 1) {
                return str_replace($this->baseUrl, '', $m[1]);
            }
        }

        return null;
    }

    /**
     * Resolve scmRepoId (the GH numeric id stored on repositories.scm_repo_id) to the
     * repo "name" segment used in /repos/{owner}/{repo} URLs. We pull this from the
     * Repository row's full_name (which is "owner/repo"); falls back to scmRepoId.
     */
    private function resolveRepoName(string $scmRepoId): string
    {
        $repo = Repository::where('scm_repo_id', $scmRepoId)->first();

        if ($repo === null) {
            return $scmRepoId;
        }

        $segments = explode('/', (string) $repo->full_name, 2);

        return $segments[1] ?? $scmRepoId;
    }

    /** @param  array<string, mixed>  $r */
    private function mapRepository(array $r): RepositoryDto
    {
        $owner = (string) ($r['owner']['login'] ?? $this->workspace->scm_owner_slug ?? '');

        return new RepositoryDto(
            scmRepoId: (string) ($r['id'] ?? ''),
            ownerSlug: $owner,
            name: (string) ($r['name'] ?? ''),
            fullName: (string) ($r['full_name'] ?? ''),
            defaultBranch: (string) ($r['default_branch'] ?? 'main'),
            isPrivate: (bool) ($r['private'] ?? true),
        );
    }

    /** @param  array<string, mixed>  $pr */
    private function mapPullRequest(array $pr): PullRequestDto
    {
        return new PullRequestDto(
            number: (int) ($pr['number'] ?? 0),
            title: (string) ($pr['title'] ?? ''),
            state: strtoupper((string) ($pr['state'] ?? '')),
            sourceBranch: (string) ($pr['head']['ref'] ?? ''),
            targetBranch: (string) ($pr['base']['ref'] ?? ''),
            headSha: (string) ($pr['head']['sha'] ?? ''),
            baseSha: (string) ($pr['base']['sha'] ?? ''),
            authorLogin: (string) ($pr['user']['login'] ?? ''),
        );
    }
}
