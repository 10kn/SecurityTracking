<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `severity_basis` records what gave a pre-notice its severity. `is_backfill` marks the entries a feed already
     * listed on its first successful poll: they are history, not news, and are never sent. `classification_failed_at`
     * is when the classifier started failing on the current text of an entry, so the admin hears when it stays down.
     * `covers_until` is the end of the time window a digest reports on: the next digest starts there.
     *
     * Pre-notices stored before this migration that the old 72-hour limit no longer sent are marked as history, so
     * the longer window does not send them now, except critical ones still within 14 days (they were never sent and
     * may be ongoing).
     */
    public function up(): void
    {
        Schema::table('advisories', function (Blueprint $table) {
            $table->string('severity_basis')->nullable()->after('severity');
            $table->boolean('is_backfill')->default(false)->after('is_dismissed');
            $table->timestamp('classification_failed_at')->nullable()->after('classified_severity');
        });

        Schema::table('chat_notifications', function (Blueprint $table) {
            $table->timestamp('covers_until')->nullable()->after('payload');
        });

        DB::table('advisories')
            ->where('kind', 'pre_notice')
            ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<', now()->subHours(72)))
            ->whereNot(fn ($query) => $query->where('severity', 'critical')->where('is_dismissed', false)->where('published_at', '>=', now()->subDays(14)))
            ->update(['is_backfill' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_notifications', function (Blueprint $table) {
            $table->dropColumn('covers_until');
        });

        Schema::table('advisories', function (Blueprint $table) {
            $table->dropColumn(['severity_basis', 'is_backfill', 'classification_failed_at']);
        });
    }
};
