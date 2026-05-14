<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

test('AC23: review_result_v1 schema matches frozen fixture byte-for-byte', function (): void {
    $live = File::get(base_path('config/cdv-rabbit/schemas/review_result_v1.json'));
    $fixture = File::get(base_path('tests/Fixtures/review_result_v1.json'));

    expect($live)->toBe(
        $fixture,
        'Schema drift detected. Bump to review_result_v2 and create a migration path; do NOT modify v1 in place.'
    );
});

test('AC23: review_result_v1 schema is valid JSON', function (): void {
    $content = File::get(base_path('config/cdv-rabbit/schemas/review_result_v1.json'));
    $decoded = json_decode($content, associative: true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($decoded)->toBeArray()
        ->and($decoded['name'])->toBe('review_result')
        ->and($decoded['strict'])->toBeTrue()
        ->and($decoded['input_schema']['additionalProperties'])->toBeFalse()
        ->and($decoded['input_schema']['required'])->toBe(['summary', 'comments']);
});

test('AC23: review_result_v1 schema enforces additionalProperties false on all nested objects', function (): void {
    $content = File::get(base_path('config/cdv-rabbit/schemas/review_result_v1.json'));
    $decoded = json_decode($content, associative: true);

    $schema = $decoded['input_schema'];

    expect($schema['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['summary']['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['comments']['items']['additionalProperties'])->toBeFalse();
});
