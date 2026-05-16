<?php

declare(strict_types=1);

use App\Services\Eval\GoldenFixture;

it('loads the docint-pr-35 golden fixture from disk', function (): void {
    $fixtureDir = base_path('tests/Eval/golden/docint-pr-35');

    $fixture = GoldenFixture::loadFromDirectory($fixtureDir);

    expect($fixture->fixtureId)->toBe('docint-pr-35');
    expect($fixture->diff)->toContain('diff --git');
    expect($fixture->prMetadata['author'] ?? null)->toBe('augusto-dmh');
    expect($fixture->prMetadata['branch'] ?? null)->toBe('test/cdv-rabbit-review-deep');
    expect($fixture->expectedFindings)->toHaveCount(4);
    expect($fixture->expectedWalkthroughMentions)
        ->toContain('three orthogonal refactors');
});

it('throws when the fixture directory does not exist', function (): void {
    GoldenFixture::loadFromDirectory('/tmp/definitely-not-a-fixture-'.uniqid());
})->throws(RuntimeException::class);

it('throws when expected_findings.json is missing', function (): void {
    $tmp = sys_get_temp_dir().'/golden-broken-'.uniqid();
    mkdir($tmp);
    file_put_contents($tmp.'/diff.patch', 'fake');
    file_put_contents($tmp.'/pr_metadata.json', '{"fixture_id":"x"}');

    try {
        GoldenFixture::loadFromDirectory($tmp);
        $this->fail('Expected RuntimeException');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('expected_findings.json');
    } finally {
        @unlink($tmp.'/diff.patch');
        @unlink($tmp.'/pr_metadata.json');
        @rmdir($tmp);
    }
});

it('loads every fixture directory under the corpus root', function (): void {
    $fixtures = GoldenFixture::loadAll(base_path('tests/Eval/golden'));

    expect($fixtures)->not->toBeEmpty();
    $ids = array_map(fn (GoldenFixture $f) => $f->fixtureId, $fixtures);
    expect($ids)->toContain('docint-pr-35');
});
