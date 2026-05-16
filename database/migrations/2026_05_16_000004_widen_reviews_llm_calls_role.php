<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Real-world testing of v2 pipeline against DocInt PR #36 revealed that
     * the W7-T4 no-op migration was incorrect: SQLite (and Postgres) materialise
     * Laravel's $table->enum('role', [...]) as a CHECK constraint that rejects
     * the new `draft` and `critique` values. Widen the column to plain string.
     *
     * The PHP-side App\Enums\LlmCallRole stays authoritative for permitted values.
     */
    public function up(): void
    {
        Schema::table('reviews_llm_calls', function (Blueprint $table): void {
            $table->string('role', 16)->change();
        });
    }

    public function down(): void
    {
        Schema::table('reviews_llm_calls', function (Blueprint $table): void {
            $table->enum('role', ['triage', 'review', 'summary'])->change();
        });
    }
};
