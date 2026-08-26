<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The short handle exposed as the `preferred_username` OIDC claim, which the
     * legacy intranet maps to `REMOTE_USER`. Not nullable: an absent claim yields an
     * empty `REMOTE_USER`, and every consumer of it is an authorization check.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
        });
    }
};
