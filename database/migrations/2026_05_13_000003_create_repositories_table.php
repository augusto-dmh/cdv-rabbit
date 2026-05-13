<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('bitbucket_uuid')->unique();
            $table->string('name');
            $table->string('full_slug')->unique();
            $table->string('webhook_uuid')->nullable();
            $table->string('webhook_token');
            $table->string('default_branch');
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
