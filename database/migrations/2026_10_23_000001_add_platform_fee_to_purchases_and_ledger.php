<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A platform fee that is ready but off (ADR 0021). Runs on the live RDS
 * database, so it only adds columns: every existing purchase gets a fee of
 * 0 and every existing ledger entry keeps an empty platform marker, so no
 * value or balance changes (FeeMigrationSafetyTest).
 *
 * - credit_purchases.platform_fee_cents: the fee fixed when the purchase
 *   was requested, paid on top of the credits.
 * - credit_ledger_entries.platform: true only on lines of the Local Goods
 *   platform account (the fee), null on every other line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_purchases', function (Blueprint $table) {
            $table->unsignedInteger('platform_fee_cents')->default(0);
        });

        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->boolean('platform')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->dropColumn('platform');
        });

        Schema::table('credit_purchases', function (Blueprint $table) {
            $table->dropColumn('platform_fee_cents');
        });
    }
};
