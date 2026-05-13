<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('file_path')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('bitbucket_comment_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->enum('comment_type', ['summary', 'inline', 'error']);
            $table->timestamps();

            $table->index('review_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_comments');
    }
};
