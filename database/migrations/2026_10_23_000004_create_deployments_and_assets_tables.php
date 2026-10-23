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
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('environment');
            $table->string('commit_sha', 64)->nullable();
            $table->string('image_digest')->nullable();
            $table->string('release')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamp('deployed_at')->nullable();
            $table->timestamps();

            $table->index(['service_id', 'environment', 'is_current']);
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('identifier')->unique();
            $table->string('kind');
            $table->string('hostname')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('asset_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->jsonb('payload');
            $table->timestamp('collected_at');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_reports');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('deployments');
    }
};
