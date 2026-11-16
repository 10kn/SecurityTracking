<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The security advisories of Amazon Linux, Ubuntu and Debian are no longer followed: a fix in a single package of
     * an image gave the admin nothing to act on. Their advisories and the heartbeats of their feeds (`os:…`) go, and so
     * do the columns only they used. The security news cards they sent stay in chat_notifications as sent messages.
     */
    public function up(): void
    {
        DB::table('advisories')->where('kind', 'os_advisory')->delete();
        DB::table('source_heartbeats')->where('source', 'like', 'os:%')->delete();

        Schema::table('advisories', function (Blueprint $table) {
            $table->dropIndex(['kind', 'operating_system', 'published_at']);
            $table->dropColumn(['operating_system', 'os_releases', 'fixed_packages']);
        });
    }

    /**
     * Reverse the migrations. The deleted advisories and heartbeats do not come back.
     */
    public function down(): void
    {
        Schema::table('advisories', function (Blueprint $table) {
            $table->string('operating_system')->nullable();
            $table->jsonb('os_releases')->nullable();
            $table->jsonb('fixed_packages')->nullable();

            $table->index(['kind', 'operating_system', 'published_at']);
        });
    }
};
