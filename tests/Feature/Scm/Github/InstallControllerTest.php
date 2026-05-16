<?php

declare(strict_types=1);

use App\Enums\WorkspaceHealth;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.github.app_slug', 'cdv-rabbit-bot');
    config()->set('services.github.app_id', '111');
    config()->set('services.github.base_url', 'https://api.github.com');

    // Throwaway RSA key so the verifyCredentials JWT mint inside callback() works.
    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $privateKey);
    config()->set('services.github.app_private_key', $privateKey);
});

function makeAdminUserAndWorkspace(string $scmProvider = 'github_cloud'): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'scm_provider' => $scmProvider,
        'github_installation_id' => null,
        'bitbucket_token' => null,
    ]);
    $workspace->users()->attach($user->id, ['role' => 'admin']);

    return [$user, $workspace];
}

test('AC29: POST install/start returns 302 to github.com installations/new and stashes the workspace id in session', function (): void {
    [$user, $workspace] = makeAdminUserAndWorkspace();

    $response = $this->actingAs($user)
        ->post(route('github.install.start', $workspace->slug));

    $response->assertStatus(302);
    $location = $response->headers->get('Location');

    // No custom ?state= — the workspace id lives in the session.
    expect($location)->toBe('https://github.com/apps/cdv-rabbit-bot/installations/new');

    $marker = session('scm_github_install');
    expect($marker)->toBeArray();
    expect($marker['workspace_id'])->toBe($workspace->id);
    expect($marker['expires_at'])->toBeGreaterThan(time());
});

test('install/start denied to non-admins (403)', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['scm_provider' => 'github_cloud']);
    // Not attached as admin.

    $this->actingAs($user)
        ->post(route('github.install.start', $workspace->slug))
        ->assertStatus(403);
});

test('AC30: callback with valid session marker + installation_id persists installation_id and marks healthy', function (): void {
    [$user, $workspace] = makeAdminUserAndWorkspace();

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_test'], 201),
        'api.github.com/installation/repositories*' => Http::response(['repositories' => []], 200),
    ]);

    $response = $this->actingAs($user)
        ->withSession(['scm_github_install' => ['workspace_id' => $workspace->id, 'expires_at' => time() + 60]])
        ->get(route('github.install.callback', ['installation_id' => '4242']));

    $response->assertStatus(302);
    $workspace->refresh();

    expect($workspace->github_installation_id)->toBe('4242');
    expect($workspace->health)->toBe(WorkspaceHealth::Healthy);
});

test('AC31: callback with missing install session returns 403 and does not persist', function (): void {
    [$user, $workspace] = makeAdminUserAndWorkspace();

    $response = $this->actingAs($user)
        ->get(route('github.install.callback', ['installation_id' => '4242']));

    $response->assertStatus(403);
    $workspace->refresh();
    expect($workspace->github_installation_id)->toBeNull();
});

test('AC31: callback with expired install session returns 403', function (): void {
    [$user, $workspace] = makeAdminUserAndWorkspace();

    $this->actingAs($user)
        ->withSession(['scm_github_install' => ['workspace_id' => $workspace->id, 'expires_at' => time() - 1]])
        ->get(route('github.install.callback', ['installation_id' => '4242']))
        ->assertStatus(403);

    $workspace->refresh();
    expect($workspace->github_installation_id)->toBeNull();
});

test('AC31: session marker is single-use (consumed on the first callback)', function (): void {
    [$user, $workspace] = makeAdminUserAndWorkspace();

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_test'], 201),
        'api.github.com/installation/repositories*' => Http::response(['repositories' => []], 200),
    ]);

    $this->actingAs($user)
        ->withSession(['scm_github_install' => ['workspace_id' => $workspace->id, 'expires_at' => time() + 60]])
        ->get(route('github.install.callback', ['installation_id' => '4242']))
        ->assertStatus(302);

    // Replay (no fresh withSession — the previous request consumed the key) is rejected.
    $this->actingAs($user)
        ->get(route('github.install.callback', ['installation_id' => '4242']))
        ->assertStatus(403);
});

test('AC32: callback with installation_id already mapped to another workspace returns 409', function (): void {
    [$user, $workspaceA] = makeAdminUserAndWorkspace();

    Workspace::factory()->create([
        'scm_provider' => 'github_cloud',
        'github_installation_id' => '9999',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['scm_github_install' => ['workspace_id' => $workspaceA->id, 'expires_at' => time() + 60]])
        ->get(route('github.install.callback', ['installation_id' => '9999']));

    $response->assertStatus(409);
    $workspaceA->refresh();
    expect($workspaceA->github_installation_id)->toBeNull();
});
