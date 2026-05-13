<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews_llm_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('model_id');
            $table->enum('role', ['triage', 'review', 'summary']);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cache_creation_input_tokens')->default(0);
            $table->unsignedInteger('cache_read_input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('request_id')->nullable();
            $table->unsignedInteger('ratelimit_tokens_remaining')->nullable();
            $table->timestamp('ratelimit_tokens_reset')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedSmallInteger('http_status')->default(200);
            $table->string('error_type')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews_llm_calls');
    }
};
