<?php

declare(strict_types=1);

use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('bitbucket_token is encrypted at rest and decrypted by accessor', function (): void {
    $plaintext = 'super-secret-bitbucket-token-12345';

    $workspace = Workspace::factory()->create(['bitbucket_token' => $plaintext]);

    $raw = DB::table('workspaces')->where('id', $workspace->id)->value('bitbucket_token');

    expect($raw)->not->toBe($plaintext);
    expect($raw)->not->toContain($plaintext);
    expect($workspace->bitbucket_token)->toBe($plaintext);
});

test('webhook_secret is encrypted at rest and decrypted by accessor', function (): void {
    $plaintext = 'webhook-secret-value-xyz';

    $workspace = Workspace::factory()->create(['webhook_secret' => $plaintext]);

    $raw = DB::table('workspaces')->where('id', $workspace->id)->value('webhook_secret');

    expect($raw)->not->toBe($plaintext);
    expect($raw)->not->toContain($plaintext);
    expect($workspace->webhook_secret)->toBe($plaintext);
});
