<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A vendor may edit a pre-notice in place: Node.js adds the fixed versions to its pre-announcement once the releases
     * are out and moves its date to the release day. `advisories.first_published_at` keeps the date the feed gave when
     * the entry was first stored, which `published_at` no longer does; `entry_hash` is the title and text read on the
     * last poll and `content_changed_at` when a poll found them changed (see SyncNoticeFeed). Existing pre-notices
     * start from their current date, so one already edited keeps the date it has now.
     *
     * `incidents.news_patches` is branch => version of the fixes the security news space was told about (see
     * SendSecurityNews); null for an incident whose news went out before it was kept, which records what it has
     * without sending anything.
     */
    public function up(): void
    {
        Schema::table('advisories', function (Blueprint $table) {
            $table->timestamp('first_published_at')->nullable()->after('published_at');
            $table->timestamp('content_changed_at')->nullable()->after('first_published_at');
            $table->string('entry_hash', 64)->nullable()->after('content_hash');
        });

        DB::table('advisories')->where('kind', 'pre_notice')->update(['first_published_at' => DB::raw('published_at')]);

        Schema::table('incidents', function (Blueprint $table) {
            $table->jsonb('news_patches')->nullable()->after('platform_scopes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('news_patches');
        });

        Schema::table('advisories', function (Blueprint $table) {
            $table->dropColumn(['first_published_at', 'content_changed_at', 'entry_hash']);
        });
    }
};
