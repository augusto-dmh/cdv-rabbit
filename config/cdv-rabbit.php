<?php

return [
    /*
     * Global kill switch — operator-only, set via env var CDV_RABBIT_KILLED=true.
     * When true, ALL workspaces stop processing reviews regardless of per-workspace setting.
     */
    'killed' => (bool) env('CDV_RABBIT_KILLED', false),
];
