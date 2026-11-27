<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A project can post to a Google Chat webhook of its own instead of its team's (`notification_target`). The URL is
     * encrypted like the team's, and kept when the project goes back to its team.
     *
     * `chat_notifications.project_id` is set on a message to a project's own webhook; it keeps its `team_id`. A project
     * deleted later leaves its messages to the team's history rather than deleting them, as the ledgers of what the
     * team was told point at them.
     *
     * `incident_team.project_id` is set on the thread of an incident in a project's own space. Such a row holds only the
     * thread: what the team was told and an admin's assessment stay on the team's row, whose `project_id` is null.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('notification_target')->default('team')->after('slug');
            $table->text('google_chat_webhook_url')->nullable()->after('notification_target');
        });

        Schema::table('chat_notifications', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('team_id')->constrained()->nullOnDelete();
        });

        Schema::table('incident_team', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('team_id')->constrained()->cascadeOnDelete();
            $table->dropUnique(['incident_id', 'team_id']);
            $table->unique(['incident_id', 'team_id', 'project_id'])->nullsNotDistinct();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incident_team', function (Blueprint $table) {
            $table->dropUnique(['incident_id', 'team_id', 'project_id']);
        });

        DB::table('incident_team')->whereNotNull('project_id')->delete();

        Schema::table('incident_team', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->unique(['incident_id', 'team_id']);
        });

        Schema::table('chat_notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['notification_target', 'google_chat_webhook_url']);
        });
    }
};
