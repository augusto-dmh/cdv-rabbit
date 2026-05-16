<?php

declare(strict_types=1);

use App\Enums\ReviewSchemaVersion;
use App\Models\Workspace;

// ---------------------------------------------------------------------------
// AC43: Workspace.review_schema_version defaults to v1 and gates v2 path
// ---------------------------------------------------------------------------

it('AC43: a freshly-created Workspace defaults to review_schema_version v1', function (): void {
    $workspace = Workspace::factory()->create();

    expect($workspace->review_schema_version)->toBe(ReviewSchemaVersion::V1);
});

it('AC43: a Workspace can be updated to review_schema_version v2', function (): void {
    $workspace = Workspace::factory()->create();

    $workspace->update(['review_schema_version' => ReviewSchemaVersion::V2]);

    expect($workspace->fresh()->review_schema_version)->toBe(ReviewSchemaVersion::V2);
});

it('AC43: the v2() factory state produces a v2 Workspace', function (): void {
    $workspace = Workspace::factory()->v2()->create();

    expect($workspace->review_schema_version)->toBe(ReviewSchemaVersion::V2);
});

it('AC43: the cast reads v1 strings from the DB as ReviewSchemaVersion::V1', function (): void {
    $workspace = Workspace::factory()->create();

    expect($workspace->fresh()->review_schema_version)
        ->toBeInstanceOf(ReviewSchemaVersion::class)
        ->toBe(ReviewSchemaVersion::V1);
});

it('AC43: invalid review_schema_version values are rejected at the cast layer', function (): void {
    $workspace = Workspace::factory()->create();

    expect(fn () => $workspace->update(['review_schema_version' => 'v99']))
        ->toThrow(ValueError::class);
});

it('AC43: the persisted column round-trips v1 and v2 string values', function (): void {
    $v1 = Workspace::factory()->create();
    $v2 = Workspace::factory()->v2()->create();

    expect($v1->review_schema_version->value)->toBe('v1')
        ->and($v2->review_schema_version->value)->toBe('v2');
});
