<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An admin pauses the monitoring of a project no longer maintained ("Ngừng theo dõi"): its services are still
     * scanned, but no alert, digest or card tells their team about them until it is resumed. `monitoring_paused_at`,
     * `monitoring_paused_by` and `monitoring_pause_reason` say who paused it, when and why; null while it is monitored.
     *
     * `project_events` keeps the history of a project's monitoring: each pause and each resume, with whether the
     * team was told what it had missed. Rows are only added.
     *
     * An admin resuming a project without telling the team ("Không gửi") leaves out what each current incident asked
     * of its services then: `project_incident_skips` keeps a snapshot of that exposure (see IncidentTeamExposure), and
     * the project's services are left out of the incident's alerts while it still covers what the incident asks now.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('monitoring_paused_at')->nullable()->after('slug');
            $table->foreignId('monitoring_paused_by')->nullable()->after('monitoring_paused_at')->constrained('users')->nullOnDelete();
            $table->text('monitoring_pause_reason')->nullable()->after('monitoring_paused_by');
        });

        Schema::create('project_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->jsonb('data')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['project_id', 'type']);
        });

        Schema::create('project_incident_skips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->jsonb('exposure');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['project_id', 'incident_id']);
            $table->index('incident_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_incident_skips');
        Schema::dropIfExists('project_events');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('monitoring_paused_by');
            $table->dropColumn(['monitoring_paused_at', 'monitoring_pause_reason']);
        });
    }
};
