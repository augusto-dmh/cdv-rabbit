<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspaces;

use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');

        return $workspace->users()
            ->where('user_id', $this->user()->id)
            ->exists();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
