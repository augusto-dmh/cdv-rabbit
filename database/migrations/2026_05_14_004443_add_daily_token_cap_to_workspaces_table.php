<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->unsignedInteger('daily_token_cap')->default(200000)->after('kill_switch_enabled');
            $table->unsignedTinyInteger('daily_token_cap_alert_threshold')->default(70)->after('daily_token_cap');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['daily_token_cap', 'daily_token_cap_alert_threshold']);
        });
    }
};
