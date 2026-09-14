<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate from `redirect_uris` because they answer different questions: one is where a
 * browser returns carrying an authorization code, the other is where a person lands after
 * signing out. Validating the second against the first rejects an app's own home page.
 *
 * Nullable, so a client that registers none simply gets no post-logout redirect — which
 * is what RP-Initiated Logout §2 requires of an unregistered value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->text('post_logout_redirect_uris')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->dropColumn('post_logout_redirect_uris');
        });
    }

    /** Matches the sibling Passport migrations, which allow a separate OAuth connection. */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
