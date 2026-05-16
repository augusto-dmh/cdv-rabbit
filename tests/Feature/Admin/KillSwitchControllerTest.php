<?php

declare(strict_types=1);

use App\Concerns\WorkspaceContext;
use App\Enums\ReviewStatus;
use App\Jobs\ReviewPullRequestJob;
use App\Models\Repository;
use App\Models\Review;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Bitbucket\BitbucketClient;
use App\Services\Llm\LlmDriverInterface;
use App\Services\Review\CostReservationInterface;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeCostReservation;
use Tests\Fakes\StubsV2LlmDriverMethods;

afterEach(function (): void {
    app(WorkspaceContext::class)->clear();
});

function makeAdminWorkspace(): array
{
    $workspace = Workspace::factory()->create(['kill_switch_enabled' => false]);
    $admin = User::factory()->create();
    $workspace->users()->attach($admin->id, ['role' => 'admin']);
    app(WorkspaceContext::class)->bind($workspace->id);

    return [$workspace, $admin];
}

test('edit returns kill switch view with current state', function (): void {
    [$workspace, $admin] = makeAdminWorkspace();

    $response = $this->actingAs($admin)
        ->get(route('workspaces.admin.kill-switch.edit', $workspace->slug));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/KillSwitch')
        ->where('workspace.kill_switch_enabled', false)
        ->has('globalKilled')
    );
});

test('update toggles kill switch on for admin', function (): void {
    [$workspace, $admin] = makeAdminWorkspace();

    $this->actingAs($admin)
        ->put(route('workspaces.admin.kill-switch.update', $workspace->slug), [
            'kill_switch_enabled' => true,
            'reason' => 'Testing pause',
        ])
        ->assertRedirect(route('workspaces.admin.kill-switch.edit', $workspace->slug));

    expect($workspace->fresh()->kill_switch_enabled)->toBeTrue();
});

test('update toggles kill switch off for admin', function (): void {
    $workspace = Workspace::factory()->create(['kill_switch_enabled' => true]);
    $admin = User::factory()->create();
    $workspace->users()->attach($admin->id, ['role' => 'admin']);
    app(WorkspaceContext::class)->bind($workspace->id);

    $this->actingAs($admin)
        ->put(route('workspaces.admin.kill-switch.update', $workspace->slug), [
            'kill_switch_enabled' => false,
        ])
        ->assertRedirect(route('workspaces.admin.kill-switch.edit', $workspace->slug));

    expect($workspace->fresh()->kill_switch_enabled)->toBeFalse();
});

test('non-admin gets 403 on update', function (): void {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create();
    app(WorkspaceContext::class)->bind($workspace->id);

    $response = $this->actingAs($member)
        ->put(route('workspaces.admin.kill-switch.update', $workspace->slug), [
            'kill_switch_enabled' => true,
        ]);

    $response->assertForbidden();
});

test('non-member gets 403 on edit', function (): void {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('workspaces.admin.kill-switch.edit', $workspace->slug));

    $response->assertForbidden();
});

test('global env flag CDV_RABBIT_KILLED stops job from calling LLM', function (): void {
    $workspace = Workspace::factory()->create(['kill_switch_enabled' => false]);
    app(WorkspaceContext::class)->bind($workspace->id);
    $repository = Repository::factory()->forWorkspace($workspace)->create();

    config(['cdv-rabbit.killed' => true]);

    $llmCalled = false;
    app()->bind(LlmDriverInterface::class, fn () => new class($llmCalled) implements LlmDriverInterface
    {
        use StubsV2LlmDriverMethods;

        public function __construct(private bool &$called) {}

        public function getSystemPrompt(): string
        {
            return '';
        }

        public function getToolSchema(): array
        {
            return [];
        }

        public function reviewDiff(string $sp, array $ts, string $msg, array $opts = []): never
        {
            $this->called = true;
            throw new RuntimeException('LLM must not be called when global kill is active');
        }
    });
    bindFakeLlmFactory();

    app()->bind(CostReservationInterface::class, fn () => new FakeCostReservation(granted: true));
    Http::fake(['*' => Http::response([])]);
    app()->bind(BitbucketClient::class, function () {
        $ws = Workspace::factory()->create();

        return new BitbucketClient($ws);
    });

    $job = new ReviewPullRequestJob(
        workspaceId: $workspace->id,
        repositoryId: $repository->id,
        pullRequestNumber: 200,
        headSha: 'globalks001',
    );

    app()->call([$job, 'handle']);

    expect($llmCalled)->toBeFalse();

    $review = Review::where('workspace_id', $workspace->id)->first();
    expect($review->status)->toBe(ReviewStatus::Skipped);
});
