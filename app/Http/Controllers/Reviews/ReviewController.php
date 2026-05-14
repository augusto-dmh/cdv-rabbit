<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reviews;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\IndexReviewsRequest;
use App\Models\Review;
use App\Models\Workspace;
use Inertia\Inertia;
use Inertia\Response;

class ReviewController extends Controller
{
    public function index(Workspace $workspace, IndexReviewsRequest $request): Response
    {
        $perPage = min((int) ($request->validated('per_page') ?? 25), 100);

        $reviews = $workspace->reviews()
            ->with(['repository', 'llmCalls'])
            ->when($request->validated('repository_id'), fn ($q, $id) => $q->where('repository_id', $id))
            ->when($request->validated('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->validated('date_from'), fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($request->validated('date_to'), fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('reviews/Index', [
            'workspace' => $workspace->only('id', 'name', 'slug'),
            'reviews' => $reviews,
            'filters' => $request->only('repository_id', 'status', 'date_from', 'date_to', 'per_page'),
        ]);
    }

    public function show(Workspace $workspace, Review $review): Response
    {
        $review->load([
            'repository',
            'comments',
            'llmCalls',
        ]);

        return Inertia::render('reviews/Show', [
            'workspace' => $workspace->only('id', 'name', 'slug'),
            'review' => $review->append('cost_usd'),
            'comments' => $review->comments->map(fn ($c) => $c->only([
                'id', 'file_path', 'line', 'bitbucket_comment_id', 'comment_type', 'posted_at', 'created_at',
            ])),
            'llmCalls' => $review->llmCalls->map(fn ($call) => [
                'id' => $call->id,
                'model_id' => $call->model_id,
                'role' => $call->role?->value,
                'input_tokens' => $call->input_tokens,
                'cache_creation_input_tokens' => $call->cache_creation_input_tokens,
                'cache_read_input_tokens' => $call->cache_read_input_tokens,
                'output_tokens' => $call->output_tokens,
                'cost_usd' => number_format(
                    ($call->input_tokens + $call->cache_creation_input_tokens + $call->output_tokens) / 1_000_000,
                    6
                ),
                'duration_ms' => $call->duration_ms,
                'http_status' => $call->http_status,
                'error_type' => $call->error_type,
            ]),
        ]);
    }
}
