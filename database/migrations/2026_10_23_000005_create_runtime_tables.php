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
        Schema::create('runtime_branches', function (Blueprint $table) {
            $table->id();
            $table->string('technology');
            $table->string('branch');
            $table->string('latest_version')->nullable();
            $table->date('released_at')->nullable();
            $table->date('active_support_until')->nullable();
            $table->date('security_support_until')->nullable();
            $table->boolean('is_eol')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['technology', 'branch']);
        });

        Schema::create('runtime_releases', function (Blueprint $table) {
            $table->id();
            $table->string('technology');
            $table->string('branch');
            $table->string('version');
            $table->boolean('is_security_release')->nullable();
            $table->date('released_at')->nullable();
            $table->timestamps();

            $table->unique(['technology', 'version']);
            $table->index(['technology', 'branch']);
        });

        Schema::create('runtimes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('technology');
            $table->string('raw_version');
            $table->string('version')->nullable();
            $table->string('branch')->nullable();
            $table->string('source');
            $table->string('status')->default('unknown');
            $table->string('status_reason')->nullable();
            $table->jsonb('status_context')->nullable();
            $table->timestamp('observed_at');
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();

            $table->unique(['deployment_id', 'technology']);
            $table->index(['technology', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('runtimes');
        Schema::dropIfExists('runtime_releases');
        Schema::dropIfExists('runtime_branches');
    }
};
