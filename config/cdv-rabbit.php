<?php

return [
    /*
     * Global kill switch — operator-only, set via env var CDV_RABBIT_KILLED=true.
     * When true, ALL workspaces stop processing reviews regardless of per-workspace setting.
     */
    'killed' => (bool) env('CDV_RABBIT_KILLED', false),

    /*
     * LGPD data-retention settings.
     * soft_delete_days: reviews older than this are soft-deleted.
     * hard_delete_grace_days: soft-deleted reviews older than this are hard-deleted.
     */
    'retention' => [
        'soft_delete_days' => (int) env('CDV_RABBIT_SOFT_DELETE_DAYS', 365),
        'hard_delete_grace_days' => (int) env('CDV_RABBIT_HARD_DELETE_GRACE_DAYS', 30),
    ],

    /*
     * Anthropic Data Processing Agreement URL.
     * Operators must set ANTHROPIC_DPA_URL before go-live.
     * The rabbit:lgpd-check command fails until this is populated.
     */
    'anthropic_dpa_url' => env('ANTHROPIC_DPA_URL'),

    /*
     * Path to the DPO sign-off JSON file.
     * The rabbit:lgpd-check command reads this file to verify operator sign-off.
     */
    'dpo_signoff_path' => env('DPO_SIGNOFF_PATH', storage_path('app/dpo-signoff.json')),

    /*
     * OpenAI Data Processing Agreement URL.
     * Operators must set OPENAI_DPA_URL before enabling any workspace with llm_provider=openai.
     * The rabbit:lgpd-check command fails until this is populated when any workspace uses OpenAI.
     */
    'openai_dpa_url' => env('OPENAI_DPA_URL'),

    /*
     * GitHub Data Processing Agreement URL.
     * Operators must set GITHUB_DPA_URL before enabling any workspace with scm_provider=github_cloud.
     * The rabbit:lgpd-check command fails until this is populated when any workspace uses GitHub.
     */
    'github_dpa_url' => env('GITHUB_DPA_URL'),

    /*
     * Cost-per-review multiplier applied to the per-job token reservation.
     * v1 reviews issue 1 LLM call. v2 reviews issue 2 calls (draft + critique),
     * so the reservation is doubled by default. Operators may tune this once
     * production telemetry confirms the cache-hit-adjusted ratio.
     */
    'cost_per_review_factor' => (int) env('CDV_RABBIT_COST_PER_REVIEW_FACTOR', 2),

    /*
     * AC51: commit-status context name posted by ReviewPullRequestJob on the PR head SHA.
     * Consumer repos add this string to their branch protection's required-status-checks
     * to gate auto-merge on cdv-rabbit reviews.
     */
    'status_check_context' => (string) env('CDV_RABBIT_STATUS_CHECK_CONTEXT', 'cdv-rabbit/review'),

    /*
     * Eval harness settings.
     *
     * cross_provider_judge: when true (default, preserves AC41), the LLM-as-judge
     * for a review produced by provider X uses provider !=X to avoid same-family
     * sycophancy. Flip to false in environments that only carry one provider's
     * credentials (e.g. local dev with only OPENAI_API_KEY) — `rabbit:eval` still
     * runs, but the judge step uses the same provider as the reviewer and loses
     * the cross-family bias control. See ADR 0007.
     */
    'eval' => [
        'cross_provider_judge' => (bool) env('CDV_RABBIT_EVAL_CROSS_PROVIDER_JUDGE', true),
    ],
];
