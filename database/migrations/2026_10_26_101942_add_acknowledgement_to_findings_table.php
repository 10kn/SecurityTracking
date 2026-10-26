<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An admin marks a finding as read ("Đã đọc") to stop its daily reminder: who did and when. Open findings get the
     * handling deadline of their severity (`psm.alerts.deadline_days`), counted from when they were first seen; an
     * end-of-life finding gets the day support ended, in the digest timezone.
     */
    public function up(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable()->after('notified_at');
            $table->foreignId('acknowledged_by')->nullable()->after('acknowledged_at')->constrained('users')->nullOnDelete();
        });

        $timezone = DB::getPdo()->quote(config('psm.digest.timezone'));

        DB::table('findings')
            ->where('state', 'open')
            ->where('rule', 'runtime_eol')
            ->whereNull('sla_due_at')
            ->update(['sla_due_at' => DB::raw("coalesce(((context->'reason_context'->>'date')::date::timestamp at time zone {$timezone}) at time zone 'UTC', first_seen_at)")]);

        foreach (config('psm.alerts.deadline_days') as $severity => $days) {
            DB::table('findings')
                ->where('state', 'open')
                ->where('severity', $severity)
                ->whereNull('sla_due_at')
                ->update(['sla_due_at' => DB::raw("first_seen_at + interval '".((int) $days)." days'")]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropColumn('acknowledged_at');
        });
    }
};
