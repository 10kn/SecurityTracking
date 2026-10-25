<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The repositories the GitHub App may read, as GitHub listed them when an admin last refreshed the list. A service
     * picks its repository from them (handover D12).
     */
    public function up(): void
    {
        Schema::create('accessible_repositories', function (Blueprint $table) {
            $table->id();
            $table->string('full_name')->unique();
            $table->string('default_branch')->nullable();
            $table->boolean('is_private')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accessible_repositories');
    }
};
