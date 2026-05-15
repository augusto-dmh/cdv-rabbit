<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reviews_llm_calls', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('model_id');
            $table->string('model')->nullable()->after('provider');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reviews_llm_calls', function (Blueprint $table) {
            $table->dropColumn(['provider', 'model']);
        });
    }
};
