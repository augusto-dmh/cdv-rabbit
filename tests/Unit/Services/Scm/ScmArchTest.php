<?php

declare(strict_types=1);

use App\Services\Scm\BitbucketDriver;
use App\Services\Scm\Contracts\ScmDriverInterface;
use App\Services\Scm\Dto\CommentHandle;
use App\Services\Scm\Dto\CredentialCheck;
use App\Services\Scm\Dto\FileChangeDto;
use App\Services\Scm\Dto\InlineCommentPayload;
use App\Services\Scm\Dto\PullRequestDto;
use App\Services\Scm\Dto\RepositoryDto;
use App\Services\Scm\Dto\WebhookHandle;
use App\Services\Scm\Exceptions\UnsupportedScmProviderException;
use App\Services\Scm\GithubDriver;

test('all SCM drivers implement ScmDriverInterface (AC37 arch)', function (): void {
    expect(class_implements(BitbucketDriver::class))->toContain(ScmDriverInterface::class);
    expect(class_implements(GithubDriver::class))->toContain(ScmDriverInterface::class);
});

test('all SCM DTOs are final readonly classes', function (): void {
    $dtos = [
        RepositoryDto::class,
        PullRequestDto::class,
        FileChangeDto::class,
        InlineCommentPayload::class,
        CommentHandle::class,
        WebhookHandle::class,
        CredentialCheck::class,
    ];

    foreach ($dtos as $dto) {
        $reflection = new ReflectionClass($dto);
        expect($reflection->isFinal())->toBeTrue("$dto must be final");
        expect($reflection->isReadOnly())->toBeTrue("$dto must be readonly");
    }
});

test('UnsupportedScmProviderException extends RuntimeException', function (): void {
    expect(is_subclass_of(UnsupportedScmProviderException::class, RuntimeException::class))->toBeTrue();
});

test('AC38: ReviewPullRequestJob is provider-agnostic (no provider-specific branching)', function (): void {
    $source = (string) file_get_contents(app_path('Jobs/ReviewPullRequestJob.php'));

    // The job must never reference a concrete provider driver, a workspace.scm_provider value,
    // or any provider literal — it must talk only to ScmDriverInterface via the factory.
    expect($source)->not->toContain('scm_provider')
        ->and($source)->not->toContain('bitbucket_cloud')
        ->and($source)->not->toContain('github_cloud')
        ->and($source)->not->toContain('BitbucketDriver')
        ->and($source)->not->toContain('GithubDriver');
});

test('AC38: CommentPoster is provider-agnostic', function (): void {
    $source = (string) file_get_contents(app_path('Services/Review/CommentPoster.php'));

    expect($source)->not->toContain('scm_provider')
        ->and($source)->not->toContain('bitbucket_cloud')
        ->and($source)->not->toContain('github_cloud')
        ->and($source)->not->toContain('BitbucketDriver')
        ->and($source)->not->toContain('GithubDriver');
});

test('app/Services/Bitbucket/ directory was removed (W6-T3 cleanup)', function (): void {
    expect(is_dir(app_path('Services/Bitbucket')))->toBeFalse();
});

test('ScmDriverInterface declares exactly the 11 contract methods', function (): void {
    $reflection = new ReflectionClass(ScmDriverInterface::class);
    $methods = array_map(fn ($m) => $m->getName(), $reflection->getMethods());

    sort($methods);
    expect($methods)->toBe([
        'deleteWebhook',
        'getChangedFiles',
        'getDiff',
        'getPullRequest',
        'getRepository',
        'listRepositories',
        'postInlineComment',
        'postPullRequestComment',
        'registerWebhook',
        'updateComment',
        'verifyCredentials',
    ]);
});
