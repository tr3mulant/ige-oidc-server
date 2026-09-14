<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The token request is a back-channel call with no session, so the auth code row is the
 * only thing spanning it and the authorize request that carried the nonce. Neither
 * Passport nor league/oauth2-server stores one, so the column is ours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table) {
            $table->string('nonce')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table) {
            $table->dropColumn('nonce');
        });
    }

    /** Matches the sibling Passport migrations, which allow a separate OAuth connection. */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
