<?php

declare(strict_types=1);

use App\Models\Workspace;
use App\Services\Scm\BitbucketDriver;
use App\Services\Scm\Exceptions\UnsupportedScmProviderException;
use App\Services\Scm\GithubDriver;
use App\Services\Scm\ScmDriverFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('factory throws when workspace has no scm_provider configured', function (): void {
    $workspace = new Workspace;
    // intentionally do not set scm_provider

    $factory = new ScmDriverFactory;

    expect(fn () => $factory->make($workspace))
        ->toThrow(UnsupportedScmProviderException::class);
});

test('factory resolves BitbucketDriver for bitbucket_cloud workspace (AC37)', function (): void {
    $workspace = Workspace::factory()->create(['scm_provider' => 'bitbucket_cloud']);

    $driver = (new ScmDriverFactory)->make($workspace);

    expect($driver)->toBeInstanceOf(BitbucketDriver::class);
});

test('factory resolves GithubDriver for github_cloud workspace (AC37)', function (): void {
    config()->set('services.github.app_id', '111');
    config()->set('services.github.app_private_key', 'unused-in-construction');

    $workspace = Workspace::factory()->create([
        'scm_provider' => 'github_cloud',
        'github_installation_id' => '99',
    ]);

    $driver = (new ScmDriverFactory)->make($workspace);

    expect($driver)->toBeInstanceOf(GithubDriver::class);
});
