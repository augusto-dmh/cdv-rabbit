<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Http\Middleware\EnsureWorkspaceMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    $this->workspace = Workspace::factory()->create();

    Route::middleware(['web', 'auth', 'workspace.member'])
        ->get('/_test/workspace/{workspace:slug}', function (): JsonResponse {
            return response()->json(['workspace_id' => app(WorkspaceContext::class)->current()]);
        });
});

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

test('non-member gets 403', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get("/_test/workspace/{$this->workspace->slug}");

    $response->assertStatus(403);
});

test('member gets 200 and WorkspaceContext is bound', function (): void {
    $user = User::factory()->create();
    $this->workspace->users()->attach($user->id, ['role' => 'admin']);

    $response = $this->actingAs($user)->get("/_test/workspace/{$this->workspace->slug}");

    $response->assertStatus(200);
    $response->assertJsonFragment(['workspace_id' => $this->workspace->id]);
});

test('WorkspaceContext is cleared after response via terminate', function (): void {
    $user = User::factory()->create();
    $this->workspace->users()->attach($user->id, ['role' => 'admin']);

    $context = app(WorkspaceContext::class);

    $request = Request::create("/_test/workspace/{$this->workspace->slug}", 'GET');
    $response = new Response;

    $middleware = new EnsureWorkspaceMember($context);
    $middleware->terminate($request, $response);

    expect($context->bound())->toBeFalse();
});
