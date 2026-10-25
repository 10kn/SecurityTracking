<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each service reads its lockfiles from its own repository, since services of a project may live in different
     * repositories (handover D12): `repository` is e.g. "acme/shop" (null = lockfiles are uploaded by hand),
     * `repository_branch` the branch to read (null = the default branch). The project-level settings are dropped; no
     * project used them yet.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('repository')->nullable()->after('repository_path');
            $table->string('repository_branch')->nullable()->after('repository');
            $table->timestamp('lockfiles_synced_at')->nullable()->after('repository_branch');
            $table->string('lockfiles_commit_sha', 40)->nullable()->after('lockfiles_synced_at');
            $table->string('lockfiles_sync_error', 1000)->nullable()->after('lockfiles_commit_sha');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['repository_branch', 'syncs_lockfiles', 'lockfiles_synced_at', 'lockfiles_commit_sha', 'lockfiles_sync_error']);
        });
    }

    /**
     * Reverse the migrations. The service settings are not moved back.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('repository_branch')->nullable()->after('repository_url');
            $table->boolean('syncs_lockfiles')->default(false)->after('repository_branch');
            $table->timestamp('lockfiles_synced_at')->nullable()->after('syncs_lockfiles');
            $table->string('lockfiles_commit_sha', 40)->nullable()->after('lockfiles_synced_at');
            $table->string('lockfiles_sync_error', 1000)->nullable()->after('lockfiles_commit_sha');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['repository', 'repository_branch', 'lockfiles_synced_at', 'lockfiles_commit_sha', 'lockfiles_sync_error']);
        });
    }
};
