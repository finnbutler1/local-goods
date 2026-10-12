<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approve/Reject is now done by a signed-in BID admin. Keep which account
 * decided, next to the name shown on screen (reviewed_by). Earlier decisions
 * made as the demo persona have no account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redemptions', function (Blueprint $table) {
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('redemptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by_user_id');
        });
    }
};
