<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('pull_request_number');
            $table->string('head_sha');
            $table->string('base_sha');
            $table->enum('status', ['queued', 'running', 'posted', 'failed', 'skipped'])->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('summary_comment_id')->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('cost_usd_cents')->default(0);
            $table->unsignedInteger('secrets_redacted')->default(0);
            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'repository_id', 'pull_request_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
