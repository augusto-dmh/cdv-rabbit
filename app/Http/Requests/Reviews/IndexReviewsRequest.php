<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use App\Enums\ReviewStatus;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexReviewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');

        return [
            'repository_id' => [
                'nullable',
                'integer',
                Rule::exists('repositories', 'id')->where('workspace_id', $workspace->id),
            ],
            'status' => [
                'nullable',
                'string',
                Rule::in(array_column(ReviewStatus::cases(), 'value')),
            ],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
