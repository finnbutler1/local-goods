<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reviewers (contest judges, HR managers, merchants, BID staff, friends) get
 * their own judge-role login from an invite link. Only a hash of the link's
 * token is kept, and it is cleared once the reviewer has set a password.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('reviewer_type')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->string('invite_token_hash', 64)->nullable();
            $table->timestamp('first_login_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('previous_visit_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'reviewer_type', 'invited_at', 'invite_token_hash', 'first_login_at',
                'last_seen_at', 'previous_visit_at', 'revoked_at',
            ]);
        });
    }
};
