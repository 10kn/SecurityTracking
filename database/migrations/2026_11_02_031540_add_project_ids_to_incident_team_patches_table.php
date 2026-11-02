<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The projects whose services a "Đã có bản vá" card listed on its branch: it only stands for the "thiếu bản vá"
     * findings of those, so a project paused when the card went out still gets its findings once resumed. Null for the
     * cards from before, which stand for every project of the team.
     */
    public function up(): void
    {
        Schema::table('incident_team_patches', function (Blueprint $table) {
            $table->jsonb('project_ids')->nullable()->after('severity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incident_team_patches', function (Blueprint $table) {
            $table->dropColumn('project_ids');
        });
    }
};
