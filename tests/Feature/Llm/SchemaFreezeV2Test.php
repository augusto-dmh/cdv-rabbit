<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

test('AC42: review_result_v2 schema matches frozen fixture byte-for-byte', function (): void {
    $live = File::get(base_path('config/cdv-rabbit/schemas/review_result_v2.json'));
    $fixture = File::get(base_path('tests/Fixtures/review_result_v2.json'));

    expect($live)->toBe(
        $fixture,
        'Schema drift detected. Bump to review_result_v3 and create a migration path; do NOT modify v2 in place.'
    );
});

test('AC42: review_result_v2 schema is valid JSON', function (): void {
    $content = File::get(base_path('config/cdv-rabbit/schemas/review_result_v2.json'));
    $decoded = json_decode($content, associative: true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($decoded)->toBeArray()
        ->and($decoded['name'])->toBe('review_result_v2')
        ->and($decoded['strict'])->toBeTrue()
        ->and($decoded['input_schema']['additionalProperties'])->toBeFalse()
        ->and($decoded['input_schema']['required'])->toBe(['summary', 'findings', 'nitpicks']);
});

test('AC42: review_result_v2 schema enforces additionalProperties false on all nested objects', function (): void {
    $content = File::get(base_path('config/cdv-rabbit/schemas/review_result_v2.json'));
    $decoded = json_decode($content, associative: true);

    $schema = $decoded['input_schema'];

    expect($schema['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['summary']['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['summary']['properties']['files_analyzed']['items']['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['findings']['items']['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['nitpicks']['items']['additionalProperties'])->toBeFalse();
});

test('AC42: review_result_v2 schema satisfies OpenAI-strict intersection — every property listed in required', function (): void {
    $content = File::get(base_path('config/cdv-rabbit/schemas/review_result_v2.json'));
    $decoded = json_decode($content, associative: true);

    $assertAllPropertiesRequired = function (array $node) use (&$assertAllPropertiesRequired): void {
        if (isset($node['type']) && $node['type'] === 'object' && isset($node['properties'])) {
            $properties = array_keys($node['properties']);
            $required = $node['required'] ?? [];

            sort($properties);
            sort($required);

            expect($required)->toBe(
                $properties,
                'OpenAI-strict intersection violated: every property must be listed in `required`.'
            );

            foreach ($node['properties'] as $child) {
                $assertAllPropertiesRequired($child);
            }
        }

        if (isset($node['type']) && $node['type'] === 'array' && isset($node['items'])) {
            $assertAllPropertiesRequired($node['items']);
        }
    };

    $assertAllPropertiesRequired($decoded['input_schema']);
});

test('AC42: review_result_v2 finding severity has NO nit value (nits live in their own array)', function (): void {
    $content = File::get(base_path('config/cdv-rabbit/schemas/review_result_v2.json'));
    $decoded = json_decode($content, associative: true);

    $severityEnum = $decoded['input_schema']['properties']['findings']['items']['properties']['severity']['enum'];

    expect($severityEnum)->toBe(['high', 'medium', 'low'])
        ->and($severityEnum)->not->toContain('nit');
});

test('AC42: review_result_v2 finding suggestion is nullable + required (OpenAI-strict trick)', function (): void {
    $content = File::get(base_path('config/cdv-rabbit/schemas/review_result_v2.json'));
    $decoded = json_decode($content, associative: true);

    $finding = $decoded['input_schema']['properties']['findings']['items'];

    expect($finding['required'])->toContain('suggestion')
        ->and($finding['properties']['suggestion']['type'])->toBe(['string', 'null']);
});
