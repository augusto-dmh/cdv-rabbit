<?php

declare(strict_types=1);

namespace App\Enums;

enum ScmProvider: string
{
    case BitbucketCloud = 'bitbucket_cloud';
    case GithubCloud = 'github_cloud';
}
