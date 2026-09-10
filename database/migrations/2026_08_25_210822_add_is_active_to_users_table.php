<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The global kill switch. Identity is global and authorization is local: this
     * column answers "may this person sign in at all", which every client application
     * inherits, and is a different question from what they are allowed to do once
     * inside any one of them.
     *
     * Defaults true so that creating an account does not require remembering to
     * activate it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
