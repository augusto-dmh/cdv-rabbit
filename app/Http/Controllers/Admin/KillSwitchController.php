<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateKillSwitchRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class KillSwitchController extends Controller
{
    public function edit(Workspace $workspace): Response
    {
        return Inertia::render('admin/KillSwitch', [
            'workspace' => $workspace->only('id', 'name', 'slug', 'kill_switch_enabled'),
            'globalKilled' => (bool) config('cdv-rabbit.killed'),
        ]);
    }

    public function update(Workspace $workspace, UpdateKillSwitchRequest $request): RedirectResponse
    {
        $enabled = $request->boolean('kill_switch_enabled');

        $workspace->update(['kill_switch_enabled' => $enabled]);

        Log::info('kill_switch_toggled', [
            'workspace_id' => $workspace->id,
            'actor_user_id' => $request->user()->id,
            'kill_switch_enabled' => $enabled,
            'reason' => $request->validated('reason'),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $enabled
                ? __('AI reviews paused for this workspace.')
                : __('AI reviews resumed for this workspace.'),
        ]);

        return to_route('workspaces.admin.kill-switch.edit', $workspace->slug);
    }
}
