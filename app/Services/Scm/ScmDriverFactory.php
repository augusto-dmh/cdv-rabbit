<?php

declare(strict_types=1);

namespace App\Services\Scm;

use App\Enums\ScmProvider;
use App\Models\Workspace;
use App\Services\Scm\Contracts\ScmDriverInterface;
use App\Services\Scm\Exceptions\UnsupportedScmProviderException;

class ScmDriverFactory
{
    public function make(Workspace $workspace): ScmDriverInterface
    {
        $provider = $workspace->scm_provider;

        if (! $provider instanceof ScmProvider) {
            throw new UnsupportedScmProviderException(
                sprintf('Workspace %d has no SCM provider configured.', $workspace->id ?? 0)
            );
        }

        return match ($provider) {
            ScmProvider::BitbucketCloud => app(BitbucketDriver::class, ['workspace' => $workspace]),
            ScmProvider::GithubCloud => app(GithubDriver::class, ['workspace' => $workspace]),
        };
    }
}
