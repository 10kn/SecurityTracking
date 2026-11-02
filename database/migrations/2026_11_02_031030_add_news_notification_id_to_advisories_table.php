<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every pre-notice that counts goes once to the admin's security news space, whether or not a team runs its
     * technology (see SendSecurityNews): `news_notification_id` is the message that told it.
     */
    public function up(): void
    {
        Schema::table('advisories', function (Blueprint $table) {
            $table->foreignId('news_notification_id')->nullable()->constrained('chat_notifications')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('advisories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('news_notification_id');
        });
    }
};
