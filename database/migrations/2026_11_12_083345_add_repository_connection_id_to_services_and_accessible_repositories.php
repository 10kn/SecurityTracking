<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The connection a service reads its repository through, and the connection that listed an accessible repository.
     * Null is the GitHub App of `psm.github`, so existing services and repositories keep reading through it. One
     * repository may be listed by several connections; a connection still used by a service cannot be deleted.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('repository_connection_id')->nullable()->after('repository')->constrained()->restrictOnDelete();
        });

        Schema::table('accessible_repositories', function (Blueprint $table) {
            $table->foreignId('repository_connection_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->dropUnique(['full_name']);
            $table->unique(['repository_connection_id', 'full_name'])->nullsNotDistinct();
        });
    }

    /**
     * Repositories listed by a connection are dropped, so `full_name` is unique again.
     */
    public function down(): void
    {
        Schema::table('accessible_repositories', function (Blueprint $table) {
            $table->dropUnique(['repository_connection_id', 'full_name']);
        });

        DB::table('accessible_repositories')->whereNotNull('repository_connection_id')->delete();

        Schema::table('accessible_repositories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repository_connection_id');
            $table->unique('full_name');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repository_connection_id');
        });
    }
};
