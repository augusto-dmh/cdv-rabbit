<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('review_comments', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('reviews_llm_calls', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('review_comments', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('reviews_llm_calls', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
