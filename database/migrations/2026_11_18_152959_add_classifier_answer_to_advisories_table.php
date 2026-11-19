<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `advisories.classifier_answer` is what the notice classifier answered for the entry, before anything was dropped
     * from it (see NoticeClassification::$answer), so an answer that lost its branches or versions can be looked into.
     * Null for an entry read before it was kept, or never read.
     */
    public function up(): void
    {
        Schema::table('advisories', function (Blueprint $table) {
            $table->jsonb('classifier_answer')->nullable()->after('classifier_revision');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('advisories', function (Blueprint $table) {
            $table->dropColumn('classifier_answer');
        });
    }
};
