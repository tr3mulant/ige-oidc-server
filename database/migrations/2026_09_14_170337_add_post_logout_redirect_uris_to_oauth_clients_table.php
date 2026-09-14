<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate from `redirect_uris`: one is where a browser returns with an authorization
 * code, the other where a person lands after signing out. Nullable, because §2 requires
 * no redirect for a value a client never registered.
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
