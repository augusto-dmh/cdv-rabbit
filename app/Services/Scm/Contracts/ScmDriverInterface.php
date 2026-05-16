<?php

declare(strict_types=1);

namespace App\Services\Scm\Contracts;

use App\Services\Scm\Dto\CommentHandle;
use App\Services\Scm\Dto\CredentialCheck;
use App\Services\Scm\Dto\FileChangeDto;
use App\Services\Scm\Dto\InlineCommentPayload;
use App\Services\Scm\Dto\PullRequestDto;
use App\Services\Scm\Dto\RepositoryDto;
use App\Services\Scm\Dto\WebhookHandle;
use Illuminate\Support\Collection;

interface ScmDriverInterface
{
    public function verifyCredentials(): CredentialCheck;

    /** @return Collection<int, RepositoryDto> */
    public function listRepositories(): Collection;

    public function getRepository(string $scmRepoId): ?RepositoryDto;

    public function getPullRequest(string $scmRepoId, int $prNumber): ?PullRequestDto;

    /** @return Collection<int, FileChangeDto> */
    public function getChangedFiles(string $scmRepoId, int $prNumber): Collection;

    public function getDiff(string $scmRepoId, int $prNumber): ?string;

    public function postPullRequestComment(string $scmRepoId, int $prNumber, string $body): CommentHandle;

    public function postInlineComment(string $scmRepoId, int $prNumber, InlineCommentPayload $payload): CommentHandle;

    public function updateComment(string $scmRepoId, int $prNumber, CommentHandle $handle, string $body): CommentHandle;

    /** BB only: registers a webhook on the repo. GitHub returns null (App handles it globally). */
    public function registerWebhook(string $scmRepoId, string $callbackUrl, string $secret): ?WebhookHandle;

    /** BB only: deletes the webhook. GitHub: no-op. */
    public function deleteWebhook(string $scmRepoId, ?WebhookHandle $handle): void;
}
