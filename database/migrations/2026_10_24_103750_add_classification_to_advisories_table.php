<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the notice classifier read from a pre-notice: `content_hash` is the hash of the entry it read, so an entry
     * the vendor edits is classified again.
     */
    public function up(): void
    {
        Schema::table('advisories', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->after('fixed_versions');
            $table->string('classified_by')->nullable()->after('content_hash');
            $table->string('classified_severity')->nullable()->after('classified_by');
            $table->boolean('is_dismissed')->default(false)->after('classified_severity');
            $table->timestamp('planned_release_at')->nullable()->after('is_dismissed');
            $table->jsonb('affected_branches')->nullable()->after('planned_release_at');
            $table->text('localized_summary')->nullable()->after('affected_branches');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('advisories', function (Blueprint $table) {
            $table->dropColumn(['content_hash', 'classified_by', 'classified_severity', 'is_dismissed', 'planned_release_at', 'affected_branches', 'localized_summary']);
        });
    }
};
