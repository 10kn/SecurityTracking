<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `last_published_at` is when the latest pre-notice of the incident that was not dismissed came out (see
     * Incident::refreshFromNotices()). A branch the vendor released after it, and after the planned release, already
     * ships the fix, so the incident rules it out (see Incident::scopeFor()). Existing incidents take it from their
     * pre-notices.
     */
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->timestamp('last_published_at')->nullable()->after('first_published_at');
        });

        DB::table('incidents')->update([
            'last_published_at' => DB::raw('(select max(advisories.published_at) from advisories where advisories.incident_id = incidents.id and advisories.is_dismissed = false)'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('last_published_at');
        });
    }
};
