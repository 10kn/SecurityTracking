<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `incident_team` holds one Google Chat thread per incident and team: its `thread_key` (an installation namespace,
     * the incident, the team and the `generation`), the `space` it was opened in, the `thread_name` Google returned once
     * a message of it was delivered, and the message that opens it. A team whose webhook changes (`webhook_hash`, a hash
     * of the URL, never the URL with its key and token) starts a new generation, as Google Chat scopes thread keys to the
     * webhook's app: its next alert opens a new thread with a full card. An incident merged into another keeps its row,
     * with `merged_into_id` pointing at the row of the incident it went into.
     *
     * It also records what the team was told beyond the severities of `incident_team_notices`: the branches the vendor
     * had confirmed as affected (`told_confirmed_branches`) and when the team was told the incident is exploited. Both
     * are written when the message is queued, with `last_notification_id`, and are never proof of delivery.
     *
     * `chat_notifications` gets the thread a message belongs to, the role it was queued for (`thread_role`), the role
     * fixed before its first HTTP attempt (`sent_as`), the full card a reply sends instead when it has to open the
     * thread (`opening_payload`), a durable `request_id` for Google Chat, the names Google returned and who asked for a
     * manual update (`requested_by`).
     */
    public function up(): void
    {
        Schema::create('incident_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('generation')->default(1);
            $table->string('thread_key');
            $table->string('space')->nullable();
            $table->string('webhook_hash', 64)->nullable();
            $table->string('thread_name')->nullable();
            $table->foreignId('opening_notification_id')->nullable()->constrained('chat_notifications')->nullOnDelete();
            $table->foreignId('last_notification_id')->nullable()->constrained('chat_notifications')->nullOnDelete();
            $table->foreignId('merged_into_id')->nullable()->constrained('incident_team')->nullOnDelete();
            $table->jsonb('told_confirmed_branches')->default('[]');
            $table->timestamp('exploited_told_at')->nullable();
            $table->timestamps();

            $table->unique(['incident_id', 'team_id']);
            $table->index('thread_key');
        });

        Schema::table('chat_notifications', function (Blueprint $table) {
            $table->foreignId('incident_team_id')->nullable()->after('team_id')->constrained('incident_team')->nullOnDelete();
            $table->string('thread_key')->nullable()->after('incident_team_id');
            $table->unsignedInteger('generation')->nullable()->after('thread_key');
            $table->string('thread_role')->nullable()->after('generation');
            $table->string('sent_as')->nullable()->after('thread_role');
            $table->jsonb('opening_payload')->nullable()->after('payload');
            $table->uuid('request_id')->nullable()->unique()->after('dedupe_key');
            $table->string('requested_by')->nullable()->after('kind');
            $table->string('google_message_name')->nullable()->after('sent_at');
            $table->string('google_thread_name')->nullable()->after('google_message_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('incident_team_id');
            $table->dropUnique(['request_id']);
            $table->dropColumn(['thread_key', 'generation', 'thread_role', 'sent_as', 'opening_payload', 'request_id', 'requested_by', 'google_message_name', 'google_thread_name']);
        });

        Schema::dropIfExists('incident_team');
    }
};
