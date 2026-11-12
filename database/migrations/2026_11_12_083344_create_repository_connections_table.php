<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A personal access token of a developer account a customer invited to its repositories, so the lockfiles of
     * repositories outside the GitHub App's account can be read (see RepositoryConnection). The token is encrypted;
     * `account_login`, `scopes` and `expires_at` are what GitHub said of it when it was last checked.
     */
    public function up(): void
    {
        Schema::create('repository_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('token');
            $table->string('account_login')->nullable();
            $table->string('scopes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repository_connections');
    }
};
