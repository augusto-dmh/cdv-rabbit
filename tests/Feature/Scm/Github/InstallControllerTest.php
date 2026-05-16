<?php

declare(strict_types=1);

use App\Enums\WorkspaceHealth;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Scm\Github\StateTokenSigner;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.github.app_slug', 'cdv-rabbit-bot');
    config()->set('services.github.app_id', '111');
    config()->set('services.github.base_url', 'https://api.github.com');

    // Generate a throwaway RSA private key for the JWT signer used by the install verifyCredentials call.
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

test('AC29: POST install/start returns 302 redirect to github.com with signed state token', function (): void {
    [$user, $workspace] = makeAdminUserAndWorkspace();

    $response = $this->actingAs($user)
        ->post(route('github.install.start', $workspace->slug));

    $response->assertStatus(302);
    $location = $response->headers->get('Location');

    expect($location)->toStartWith('https://github.com/apps/cdv-rabbit-bot/installations/new?state=');

    // Extract token, verify signature using the same signer.
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $token = $query['state'] ?? '';

    expect($token)->toBeString()->not->toBe('');

    $payload = app(StateTokenSigner::class)->verify((string) $token);
    expect($payload)->not->toBeNull();
    expect($payload['w'])->toBe($workspace->id);
    expect($payload['exp'])->toBeGreaterThan(time());
});

test('install/start denied to non-admins (403)', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['scm_provider' => 'github_cloud']);
    // Not attached as admin.

    $this->actingAs($user)
        ->post(route('github.install.start', $workspace->slug))
        ->assertStatus(403);
});

test('AC30: callback with valid state + installation_id persists installation_id and marks healthy', function (): void {
    [$user, $workspace] = makeAdminUserAndWorkspace();

    $token = app(StateTokenSigner::class)->sign($workspace->id);

    // Fake the installation token exchange + verifyCredentials calls.
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_test'], 201),
        'api.github.com/installation/repositories*' => Http::response(['repositories' => []], 200),
    ]);

    $response = $this->actingAs($user)
        ->get(route('github.install.callback', ['state' => $token, 'installation_id' => '4242']));

    $response->assertStatus(302);
    $workspace->refresh();

    expect($workspace->github_installation_id)->toBe('4242');
    expect($workspace->health)->toBe(WorkspaceHealth::Healthy);
});

test('AC31: callback with invalid state returns 403 and does not persist', function (): void {
    [$user, $workspace] = makeAdminUserAndWorkspace();

    $response = $this->actingAs($user)
        ->get(route('github.install.callback', ['state' => 'bogus.token', 'installation_id' => '4242']));

    $response->assertStatus(403);
    $workspace->refresh();
    expect($workspace->github_installation_id)->toBeNull();
});

test('AC31: callback with replayed (already-consumed) state returns 403', function (): void {
    [$user, $workspace] = makeAdminUserAndWorkspace();

    $token = app(StateTokenSigner::class)->sign($workspace->id);

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_test'], 201),
        'api.github.com/installation/repositories*' => Http::response(['repositories' => []], 200),
    ]);

    // First call consumes the nonce.
    $this->actingAs($user)
        ->get(route('github.install.callback', ['state' => $token, 'installation_id' => '4242']))
        ->assertStatus(302);

    // Replay rejected.
    $this->actingAs($user)
        ->get(route('github.install.callback', ['state' => $token, 'installation_id' => '4242']))
        ->assertStatus(403);
});

test('AC32: callback with installation_id already mapped to another workspace returns 409', function (): void {
    [$user, $workspaceA] = makeAdminUserAndWorkspace();

    // Pre-existing mapping on a different workspace.
    $workspaceB = Workspace::factory()->create([
        'scm_provider' => 'github_cloud',
        'github_installation_id' => '9999',
    ]);

    $token = app(StateTokenSigner::class)->sign($workspaceA->id);

    $response = $this->actingAs($user)
        ->get(route('github.install.callback', ['state' => $token, 'installation_id' => '9999']));

    $response->assertStatus(409);
    $workspaceA->refresh();
    $workspaceB->refresh();
    expect($workspaceA->github_installation_id)->toBeNull();
    expect($workspaceB->github_installation_id)->toBe('9999');
});
