<?php

declare(strict_types=1);

/**
 * Phase 6 / Multi-SCM Provider Support verifier. Asserts that every new
 * Acceptance Criterion (AC27..AC38) is covered by at least one named test
 * elsewhere in the suite. The check is grep-based against the test source
 * tree so this stays decoupled from runtime fixtures.
 */
function scanForAc(string $marker): array
{
    $hits = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('tests')));

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());
        if (str_contains($contents, $marker)) {
            $hits[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    return $hits;
}

dataset('phase6_acs', [
    'AC27' => ['AC27'],
    'AC28' => ['AC28'],
    'AC29' => ['AC29'],
    'AC30' => ['AC30'],
    'AC31' => ['AC31'],
    'AC32' => ['AC32'],
    'AC33' => ['AC33'],
    'AC34' => ['AC34'],
    'AC35' => ['AC35'],
    'AC36' => ['AC36'],
    'AC37' => ['AC37'],
    'AC38' => ['AC38'],
]);

test('Phase 6 AC is covered by at least one named test', function (string $ac): void {
    $hits = scanForAc("{$ac}:");

    // Allow either "AC37:" prose form or "AC37 " label.
    if ($hits === []) {
        $hits = scanForAc("({$ac} ");
    }
    if ($hits === []) {
        $hits = scanForAc("{$ac} arch");
    }

    expect($hits)->not->toBeEmpty("Phase 6 {$ac} has no test referencing it.");
})->with('phase6_acs');
