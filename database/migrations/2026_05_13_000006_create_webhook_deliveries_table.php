<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('bitbucket_uuid')->unique();
            $table->foreignId('repository_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type');
            $table->enum('status', ['received', 'dispatched', 'duplicate', 'invalid'])->default('received');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
