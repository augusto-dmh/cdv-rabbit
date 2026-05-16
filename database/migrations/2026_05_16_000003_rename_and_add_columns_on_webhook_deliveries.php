<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->renameColumn('bitbucket_uuid', 'scm_delivery_id');
            $table->string('scm_provider')->nullable()->after('scm_delivery_id');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropColumn('scm_provider');
            $table->renameColumn('scm_delivery_id', 'bitbucket_uuid');
        });
    }
};
