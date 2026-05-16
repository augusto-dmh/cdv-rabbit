<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('scm_provider')->default('bitbucket_cloud')->after('owner_id');
            $table->renameColumn('bitbucket_workspace_slug', 'scm_owner_slug');
            $table->string('github_installation_id')->nullable()->unique()->after('webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropUnique(['github_installation_id']);
            $table->dropColumn('github_installation_id');
            $table->renameColumn('scm_owner_slug', 'bitbucket_workspace_slug');
            $table->dropColumn('scm_provider');
        });
    }
};
