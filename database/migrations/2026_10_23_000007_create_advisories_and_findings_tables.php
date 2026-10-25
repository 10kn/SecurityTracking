<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('advisories', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('external_id');
            $table->string('kind');
            $table->jsonb('aliases')->default('[]');
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('severity')->default('unknown');
            $table->decimal('cvss_score', 3, 1)->nullable();
            $table->string('technology')->nullable();
            $table->string('url')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('source_modified_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->jsonb('fixed_versions')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
        });

        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('advisory_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('runtime_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('component_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rule');
            $table->string('severity');
            $table->string('state')->default('open');
            $table->string('title');
            $table->string('group_key');
            $table->jsonb('context')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('accepted_until')->nullable();
            $table->string('notified_severity')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'state']);
            $table->index(['state', 'severity']);
        });

        Schema::create('finding_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finding_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->jsonb('data')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finding_events');
        Schema::dropIfExists('findings');
        Schema::dropIfExists('advisories');
    }
};
