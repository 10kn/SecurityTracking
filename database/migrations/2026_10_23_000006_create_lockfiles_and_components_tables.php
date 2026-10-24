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
        Schema::create('lockfiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->string('ecosystem');
            $table->string('path');
            $table->longText('content');
            $table->char('content_hash', 64);
            $table->char('parsed_hash', 64)->nullable();
            $table->string('parse_error', 500)->nullable();
            $table->unsignedInteger('package_count')->nullable();
            $table->timestamp('uploaded_at');
            $table->timestamps();

            $table->unique(['deployment_id', 'path']);
        });

        Schema::create('components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->string('ecosystem');
            $table->string('name');
            $table->string('version');
            $table->string('purl');
            $table->string('lockfile_path');
            $table->boolean('is_dev')->default(false);
            $table->timestamps();

            $table->unique(['deployment_id', 'lockfile_path', 'ecosystem', 'name', 'version']);
            $table->index(['ecosystem', 'name', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('components');
        Schema::dropIfExists('lockfiles');
    }
};
