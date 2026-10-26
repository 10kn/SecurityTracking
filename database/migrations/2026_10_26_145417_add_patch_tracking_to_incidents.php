<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `patch_versions` are the versions a pre-notice tells to update to ("Update to PHP 8.5.12, 8.4.27 …"), read from
     * its full text when it is stored. `incident_team_patches` records each "Đã có bản vá" card: the team, the
     * pre-notice that named the version, the branch and version, and the severity of the incident then, which the
     * team's findings of that branch take on. A pre-notice the vendor edits to a later version gets another card.
     */
    public function up(): void
    {
        Schema::table('advisories', function (Blueprint $table) {
            $table->jsonb('patch_versions')->nullable()->after('unaffected_branches');
        });

        Schema::create('incident_team_patches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('advisory_id')->constrained()->cascadeOnDelete();
            $table->string('technology');
            $table->string('branch');
            $table->string('version');
            $table->string('severity');
            $table->foreignId('chat_notification_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['team_id', 'advisory_id', 'branch', 'version']);
            $table->index(['team_id', 'technology', 'branch']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_team_patches');

        Schema::table('advisories', function (Blueprint $table) {
            $table->dropColumn('patch_versions');
        });
    }
};
