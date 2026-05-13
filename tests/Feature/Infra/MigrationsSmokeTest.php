<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

test('all domain tables exist after migrate:fresh', function (): void {
    $expectedTables = [
        'workspaces',
        'repositories',
        'reviews',
        'review_comments',
        'webhook_deliveries',
        'reviews_llm_calls',
        'workspace_user',
    ];

    foreach ($expectedTables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Table [{$table}] should exist after migration");
    }
});

test('workspaces table has expected columns', function (): void {
    expect(Schema::hasColumns('workspaces', [
        'id', 'name', 'slug', 'owner_id', 'bitbucket_workspace_slug',
        'bitbucket_token', 'bitbucket_service_account', 'webhook_secret',
        'kill_switch_enabled', 'health', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

test('reviews table has workspace_id for tenancy', function (): void {
    expect(Schema::hasColumn('reviews', 'workspace_id'))->toBeTrue();
    expect(Schema::hasColumn('reviews', 'repository_id'))->toBeTrue();
    expect(Schema::hasColumn('reviews', 'status'))->toBeTrue();
});

test('reviews_llm_calls table has all token tracking columns', function (): void {
    expect(Schema::hasColumns('reviews_llm_calls', [
        'input_tokens',
        'cache_creation_input_tokens',
        'cache_read_input_tokens',
        'output_tokens',
        'request_id',
    ]))->toBeTrue();
});
