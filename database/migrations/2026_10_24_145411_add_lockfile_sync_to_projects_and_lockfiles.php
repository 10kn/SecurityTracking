<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A project can read the lockfiles of its services from its GitHub repository every day (handover D12):
     * `repository_branch` is the branch to read (null = the default branch), `lockfiles_synced_at` the last sync
     * without errors, `lockfiles_commit_sha` the commit the last sync read. Each lockfile records where it came from.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('repository_branch')->nullable()->after('repository_url');
            $table->boolean('syncs_lockfiles')->default(false)->after('repository_branch');
            $table->timestamp('lockfiles_synced_at')->nullable()->after('syncs_lockfiles');
            $table->string('lockfiles_commit_sha', 40)->nullable()->after('lockfiles_synced_at');
            $table->string('lockfiles_sync_error', 1000)->nullable()->after('lockfiles_commit_sha');
        });

        Schema::table('lockfiles', function (Blueprint $table) {
            $table->string('source')->default('upload')->after('path');
            $table->string('commit_sha', 40)->nullable()->after('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lockfiles', function (Blueprint $table) {
            $table->dropColumn(['source', 'commit_sha']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['repository_branch', 'syncs_lockfiles', 'lockfiles_synced_at', 'lockfiles_commit_sha', 'lockfiles_sync_error']);
        });
    }
};
