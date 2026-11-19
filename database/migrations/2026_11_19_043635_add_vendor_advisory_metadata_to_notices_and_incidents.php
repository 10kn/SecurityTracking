<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advisories', function (Blueprint $table) {
            $table->jsonb('vendor_advisory')->nullable();
            $table->jsonb('cna_record')->nullable();
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->jsonb('version_scopes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('version_scopes');
        });

        Schema::table('advisories', function (Blueprint $table) {
            $table->dropColumn(['vendor_advisory', 'cna_record']);
        });
    }
};
