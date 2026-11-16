<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The security advisories of an operating system (see SyncOsAdvisoryFeed): `operating_system` is the distribution,
     * `os_releases` the releases it fixes that still count, e.g. ["22.04", "24.04"], and `fixed_packages` the source
     * packages and versions that fix it, per release.
     */
    public function up(): void
    {
        Schema::table('advisories', function (Blueprint $table) {
            $table->string('operating_system')->nullable();
            $table->jsonb('os_releases')->nullable();
            $table->jsonb('fixed_packages')->nullable();

            $table->index(['kind', 'operating_system', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('advisories', function (Blueprint $table) {
            $table->dropIndex(['kind', 'operating_system', 'published_at']);
            $table->dropColumn(['operating_system', 'os_releases', 'fixed_packages']);
        });
    }
};
