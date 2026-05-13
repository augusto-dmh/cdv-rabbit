<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspaces;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreateWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'regex:/^[a-z0-9-]+$/', 'unique:workspaces,slug'],
        ];
    }
}
