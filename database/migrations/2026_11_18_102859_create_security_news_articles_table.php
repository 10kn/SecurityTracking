<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_news_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('url')->unique();
            $table->string('section');
            $table->text('title');
            $table->date('listed_on')->index();
            $table->timestamp('listed_seen_at');
            $table->date('published_on');
            $table->boolean('is_backfill');
            $table->string('classification_key', 64)->nullable();
            $table->string('attempt_key', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->text('summary')->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('matched_tags')->nullable();
            $table->jsonb('cve_ids')->nullable();
            $table->timestamp('notification_queued_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_news_articles');
    }
};
