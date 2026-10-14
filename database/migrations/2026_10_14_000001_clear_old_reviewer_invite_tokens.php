<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reviewers now sign in with reusable magic links (ADR 0010). Tokens from the
 * old single-use password invites are cleared so none of them can be turned
 * into a magic link. The admin makes each reviewer a new link.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNotNull('invite_token_hash')->update(['invite_token_hash' => null]);
    }

    public function down(): void
    {
        // Nothing to restore: the old tokens are gone on purpose.
    }
};
