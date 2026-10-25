<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The merchant transaction fee, ready but at 0 (ADR 0029). Runs on the live
 * RDS database, so it only adds: every existing redemption, payout and
 * correction gets a fee of 0 and no ledger entry or balance changes
 * (MerchantFeeMigrationSafetyTest). No setting row is written: no row
 * means no fee.
 *
 * - merchant_fee_changes: every change to the setting (append-only), so
 *   the newest row is the current fee and the table is the audit log.
 * - redemptions.merchant_fee_*: the fee snapshotted when it was made.
 * - merchant_payouts.fee_cents: the fee held back from a Stripe row's
 *   transfer (amount_cents + fee_cents is the sale).
 * - corrections.fee_cents: the fee share a redemption refund gives back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_fee_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('fee_bps');
            $table->unsignedSmallInteger('fixed_cents');
            $table->unsignedSmallInteger('previous_bps');
            $table->unsignedSmallInteger('previous_fixed_cents');
            $table->text('reason');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('redemptions', function (Blueprint $table) {
            $table->unsignedSmallInteger('merchant_fee_bps')->default(0);
            $table->unsignedSmallInteger('merchant_fee_fixed_cents')->default(0);
            $table->unsignedInteger('merchant_fee_cents')->default(0);
        });

        Schema::table('merchant_payouts', function (Blueprint $table) {
            $table->unsignedInteger('fee_cents')->default(0);
        });

        Schema::table('corrections', function (Blueprint $table) {
            $table->unsignedInteger('fee_cents')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('corrections', fn (Blueprint $table) => $table->dropColumn('fee_cents'));
        Schema::table('merchant_payouts', fn (Blueprint $table) => $table->dropColumn('fee_cents'));
        Schema::table('redemptions', fn (Blueprint $table) => $table->dropColumn(['merchant_fee_bps', 'merchant_fee_fixed_cents', 'merchant_fee_cents']));
        Schema::dropIfExists('merchant_fee_changes');
    }
};
