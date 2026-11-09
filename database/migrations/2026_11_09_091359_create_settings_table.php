<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The values an admin changed on the "Cài đặt hệ thống" screen, over the defaults of config/psm.php (the .env).
     * A key has a row only while its value differs from that default. `value` is encrypted, as it holds the Google
     * Chat webhooks, whose URL carries the space's key and token. `updated_by` is the admin who set it last.
     *
     * `setting_changes` keeps the history of every change, only ever added to: the old and the new value, null for a
     * webhook, whose value is never written in clear.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('setting_changes', function (Blueprint $table) {
            $table->id();
            $table->string('key')->index();
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('setting_changes');
        Schema::dropIfExists('settings');
    }
};
