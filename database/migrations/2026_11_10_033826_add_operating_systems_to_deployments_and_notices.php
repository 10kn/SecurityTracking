<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `deployments.os` is the operating system the deployment runs on (see OperatingSystem); null when not known, which
     * counts as possibly affected. Existing deployments are left unknown.
     *
     * `advisories.platforms` are the operating systems a pre-notice says it is limited to, with the sentence of the
     * entry that says so (`platforms_evidence`); empty when it states no limit (see SyncNoticeFeed).
     * `classifier_revision` is the revision of the classifier that read it, so a changed prompt reads it again.
     *
     * `incidents.platform_scopes` is what the pre-notices say per operating system once one of them is limited to some
     * (see Incident::refreshFromNotices()); null when none is.
     */
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->string('os')->nullable()->after('environment');
        });

        Schema::table('advisories', function (Blueprint $table) {
            $table->jsonb('platforms')->nullable()->after('unaffected_branches');
            $table->text('platforms_evidence')->nullable()->after('platforms');
            $table->unsignedSmallInteger('classifier_revision')->nullable()->after('classified_by');
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->jsonb('platform_scopes')->nullable()->after('unaffected_branches');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('platform_scopes');
        });

        Schema::table('advisories', function (Blueprint $table) {
            $table->dropColumn(['platforms', 'platforms_evidence', 'classifier_revision']);
        });

        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn('os');
        });
    }
};
