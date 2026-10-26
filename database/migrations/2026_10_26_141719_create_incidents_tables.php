<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An incident groups the pre-notices of one security issue of a technology. A pre-notice joins an existing incident
     * only on firm evidence (a shared CVE or GHSA id, a direct link) or by an admin; `incident_basis` and
     * `incident_evidence` record which. Weaker hints (a notice published around another's planned release, the
     * classifier) only become suggestions. An incident merged into another keeps its row, with `merged_into_id`.
     *
     * The other columns of `incidents` are derived from its pre-notices that were not dismissed. `changed_at` moves only
     * when what the incident asks of the teams changes (members, severities, branches), not when a notice is re-read.
     *
     * `incident_team_notices` records which pre-notice a team was told about, at which severity, in which card: what a
     * team was told about an incident is read from the pre-notices it holds now, so merging and splitting stay right.
     *
     * Pre-notices stored before this migration get their incident on the next run of psm:watch-notices or the digest,
     * oldest first, without sending anything they had not sent before.
     */
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('technology');
            $table->string('title');
            $table->string('severity')->default('unknown');
            $table->timestamp('first_published_at')->nullable();
            $table->timestamp('planned_release_at')->nullable();
            $table->jsonb('affected_branches')->default('[]');
            $table->jsonb('unaffected_branches')->default('[]');
            $table->foreignId('merged_into_id')->nullable()->constrained('incidents')->nullOnDelete();
            $table->string('fingerprint', 64)->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();

            $table->index(['technology', 'merged_into_id']);
        });

        Schema::table('advisories', function (Blueprint $table) {
            $table->jsonb('unaffected_branches')->nullable()->after('affected_branches');
            $table->jsonb('links')->nullable()->after('url');
            $table->foreignId('incident_id')->nullable()->after('kind')->constrained()->nullOnDelete();
            $table->string('incident_basis')->nullable()->after('incident_id');
            $table->jsonb('incident_evidence')->nullable()->after('incident_basis');

            $table->index('incident_id');
        });

        Schema::create('incident_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advisory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->string('reason');
            $table->jsonb('evidence')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['advisory_id', 'incident_id', 'reason']);
            $table->index(['status', 'incident_id']);
        });

        Schema::create('incident_team_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('advisory_id')->constrained()->cascadeOnDelete();
            $table->string('severity');
            $table->string('reason');
            $table->foreignId('chat_notification_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['team_id', 'advisory_id', 'severity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_team_notices');
        Schema::dropIfExists('incident_suggestions');

        Schema::table('advisories', function (Blueprint $table) {
            $table->dropIndex(['incident_id']);
            $table->dropConstrainedForeignId('incident_id');
            $table->dropColumn(['unaffected_branches', 'links', 'incident_basis', 'incident_evidence']);
        });

        Schema::dropIfExists('incidents');
    }
};
