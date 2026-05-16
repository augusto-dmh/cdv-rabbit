<?php

declare(strict_types=1);

namespace App\Services\Eval;

use RuntimeException;

final class GoldenFixture
{
    /**
     * @param  array<string, mixed>  $prMetadata
     * @param  list<array<string, mixed>>  $expectedFindings
     * @param  list<string>  $expectedWalkthroughMentions
     */
    public function __construct(
        public readonly string $fixtureId,
        public readonly string $diff,
        public readonly array $prMetadata,
        public readonly array $expectedFindings,
        public readonly array $expectedWalkthroughMentions,
    ) {}

    public static function loadFromDirectory(string $directory): self
    {
        if (! is_dir($directory)) {
            throw new RuntimeException("Golden fixture directory not found: {$directory}");
        }

        $diffPath = $directory.'/diff.patch';
        $metadataPath = $directory.'/pr_metadata.json';
        $expectedPath = $directory.'/expected_findings.json';

        foreach ([$diffPath, $metadataPath, $expectedPath] as $required) {
            if (! file_exists($required)) {
                throw new RuntimeException("Golden fixture missing required file: {$required}");
            }
        }

        $metadata = json_decode((string) file_get_contents($metadataPath), true);
        $expected = json_decode((string) file_get_contents($expectedPath), true);

        if (! is_array($metadata)) {
            throw new RuntimeException("pr_metadata.json is not valid JSON: {$metadataPath}");
        }
        if (! is_array($expected)) {
            throw new RuntimeException("expected_findings.json is not valid JSON: {$expectedPath}");
        }

        $fixtureId = (string) ($metadata['fixture_id'] ?? basename($directory));
        $expectedFindings = $expected['expected_findings'] ?? [];
        $expectedMentions = $expected['expected_walkthrough_mentions'] ?? [];

        return new self(
            fixtureId: $fixtureId,
            diff: (string) file_get_contents($diffPath),
            prMetadata: $metadata,
            expectedFindings: array_values($expectedFindings),
            expectedWalkthroughMentions: array_values(array_map('strval', $expectedMentions)),
        );
    }

    /** @return list<self> */
    public static function loadAll(string $rootDirectory): array
    {
        if (! is_dir($rootDirectory)) {
            throw new RuntimeException("Golden corpus root not found: {$rootDirectory}");
        }

        $fixtures = [];

        foreach (scandir($rootDirectory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $rootDirectory.'/'.$entry;
            if (! is_dir($path)) {
                continue;
            }

            $fixtures[] = self::loadFromDirectory($path);
        }

        return $fixtures;
    }
}
