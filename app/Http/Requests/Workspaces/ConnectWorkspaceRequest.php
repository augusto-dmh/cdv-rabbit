<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspaces;

use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConnectWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');

        return $workspace->users()
            ->where('user_id', $this->user()->id)
            ->where('role', 'admin')
            ->exists();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'bitbucket_workspace_slug' => ['required', 'string', 'regex:/^[a-z0-9_-]+$/'],
            'bitbucket_token' => ['required', 'string', 'min:20'],
            'bitbucket_service_account' => ['required', 'string'],
        ];
    }
}
