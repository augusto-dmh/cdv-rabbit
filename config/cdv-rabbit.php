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
];
