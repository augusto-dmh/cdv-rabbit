<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('bitbucket_workspace_slug')->unique();
            $table->text('bitbucket_token');
            $table->string('bitbucket_service_account');
            $table->text('webhook_secret');
            $table->boolean('kill_switch_enabled')->default(false);
            $table->enum('health', ['healthy', 'degraded', 'unhealthy', 'paused'])->default('healthy');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
