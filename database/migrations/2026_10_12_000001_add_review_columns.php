<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person at the BID can now approve or reject a held redemption. The
 * redemption keeps who decided and when; the ledger entry for the decision
 * keeps who decided (its created_at is when).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redemptions', function (Blueprint $table) {
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
        });

        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->string('decided_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->dropColumn('decided_by');
        });

        Schema::table('redemptions', function (Blueprint $table) {
            $table->dropColumn(['reviewed_by', 'reviewed_at']);
        });
    }
};
