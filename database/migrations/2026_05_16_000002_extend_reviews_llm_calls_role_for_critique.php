<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * W7-T4: extend reviews_llm_calls.role to cover the v2 two-call pipeline.
     *
     * The role column is already a varchar (verified in tinker before this
     * migration was written), so no schema change is required to accept the
     * new `draft` and `critique` values. This migration documents the new
     * enum surface — alongside the LlmCallRole enum gaining the cases — so
     * a future operator inspecting the migration history understands when
     * the role surface expanded.
     *
     * The original create migration declared `enum('triage','review','summary')`
     * via Laravel's Blueprint, which on the current driver (sqlite in tests,
     * pgsql in prod) renders to varchar without a CHECK constraint. Adding a
     * CHECK now would break existing rows on environments that already have
     * `triage|review|summary` data, so this migration is intentionally a
     * no-op at the schema level. The PHP-side enum in App\Enums\LlmCallRole
     * is the authoritative contract.
     */
    public function up(): void
    {
        // No-op: reviews_llm_calls.role is varchar; the new `draft` and
        // `critique` values are added at the PHP enum layer only.
    }

    public function down(): void
    {
        // No-op.
    }
};
