<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An admin marks an incident as read ("Đã đọc") for a team, with whether it affects the team (`impact`: yes, no or
     * unknown; null while nobody assessed it, which is not the same as an explicit "Chưa rõ") and an optional note. The
     * assessment holds only for what the admin saw: `acknowledged_exposure` is a snapshot of the team's exposure then
     * (the incident's pre-notices, the team's services with their branch, scope and fix, the severity and exploitation)
     * and `acknowledged_exposure_hash` its hash. Whether it still holds is computed from the current exposure each time
     * it is read (see IncidentTeamExposure), never stored. `assessment_revision` counts the assessments and resets of
     * the row, so two admins assessing it at once never overwrite each other.
     *
     * `incident_team_events` keeps the history of a row: each assessment, each reset of an assessment that stopped
     * holding, and each urgent update an admin sent. Rows are only added.
     */
    public function up(): void
    {
        Schema::table('incident_team', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable()->after('exploited_told_at');
            $table->foreignId('acknowledged_by')->nullable()->after('acknowledged_at')->constrained('users')->nullOnDelete();
            $table->string('impact')->nullable()->after('acknowledged_by');
            $table->text('impact_note')->nullable()->after('impact');
            $table->jsonb('acknowledged_exposure')->nullable()->after('impact_note');
            $table->string('acknowledged_exposure_hash', 64)->nullable()->after('acknowledged_exposure');
            $table->unsignedInteger('assessment_revision')->default(0)->after('acknowledged_exposure_hash');
        });

        Schema::create('incident_team_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_team_id')->constrained('incident_team')->cascadeOnDelete();
            $table->string('type');
            $table->jsonb('data')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['incident_team_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_team_events');

        Schema::table('incident_team', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropColumn(['acknowledged_at', 'impact', 'impact_note', 'acknowledged_exposure', 'acknowledged_exposure_hash', 'assessment_revision']);
        });
    }
};
