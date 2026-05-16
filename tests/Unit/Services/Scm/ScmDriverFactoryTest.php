<?php

declare(strict_types=1);

use App\Models\Workspace;
use App\Services\Scm\Exceptions\UnsupportedScmProviderException;
use App\Services\Scm\ScmDriverFactory;

test('factory throws when workspace has no scm_provider configured', function (): void {
    $workspace = new Workspace;
    // intentionally do not set scm_provider

    $factory = new ScmDriverFactory;

    expect(fn () => $factory->make($workspace))
        ->toThrow(UnsupportedScmProviderException::class);
});
