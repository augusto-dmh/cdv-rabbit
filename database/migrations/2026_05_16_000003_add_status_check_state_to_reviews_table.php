<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            // AC51: nullable so reviews that never gated (errored before status posting)
            // stay NULL. Values: 'pending' | 'success' | 'failure'.
            $table->string('status_check_state', 16)->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('status_check_state');
        });
    }
};
