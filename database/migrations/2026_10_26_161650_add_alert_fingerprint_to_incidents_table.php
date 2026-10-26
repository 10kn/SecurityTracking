<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `alert_fingerprint` is a hash of everything a pre-notice alert run reads for the incident (its pre-notices, its
     * confirmed fixes, the runtimes of its technology and what each team was told), stored after a full run. A run
     * that finds the same fingerprint has nothing new to send for it and skips it.
     */
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->string('alert_fingerprint', 64)->nullable()->after('fingerprint');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('alert_fingerprint');
        });
    }
};
