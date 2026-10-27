<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lockfiles are found in the subdirectories of a service too. `lockfile_excludes` lists the paths, relative to the
     * service directory, that are not searched, e.g. ["legacy", "tools/old/package-lock.json"] (null = none).
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->json('lockfile_excludes')->nullable()->after('repository_branch');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('lockfile_excludes');
        });
    }
};
