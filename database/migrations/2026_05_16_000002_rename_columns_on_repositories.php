<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->renameColumn('bitbucket_uuid', 'scm_repo_id');
            $table->renameColumn('full_slug', 'full_name');
            $table->renameColumn('webhook_uuid', 'scm_webhook_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->renameColumn('scm_repo_id', 'bitbucket_uuid');
            $table->renameColumn('full_name', 'full_slug');
            $table->renameColumn('scm_webhook_uuid', 'webhook_uuid');
        });
    }
};
