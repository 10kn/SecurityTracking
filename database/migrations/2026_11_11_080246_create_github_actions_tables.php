<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GitHub Actions used by the workflows of a service's repository are tracked like packages.
     *
     * `github_action_versions` is what a reference in a workflow, e.g. `actions/checkout@v4`, pointed at when last
     * checked: the most precise release tag on the same commit (`version`, e.g. "4.2.2"), the commit and the runtime the
     * action declares (`runs_using`, e.g. "node20"). OSV cannot compare versions of GitHub Actions, so the hub does,
     * from the version ranges of each advisory (`advisories.affected_ranges`).
     *
     * `github_runner_images` is the end of life of each GitHub-hosted runner image (endoflife.date), by image name.
     */
    public function up(): void
    {
        Schema::create('github_action_versions', function (Blueprint $table) {
            $table->id();
            $table->string('action');
            $table->string('ref');
            $table->string('version')->nullable();
            $table->string('commit_sha', 40)->nullable();
            $table->string('runs_using')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['action', 'ref']);
        });

        Schema::create('github_runner_images', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->date('eol_from')->nullable();
            $table->boolean('is_eol')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::table('advisories', function (Blueprint $table) {
            $table->json('affected_ranges')->nullable()->after('fixed_versions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('advisories', function (Blueprint $table) {
            $table->dropColumn('affected_ranges');
        });

        Schema::dropIfExists('github_runner_images');
        Schema::dropIfExists('github_action_versions');
    }
};
