<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe Connect in test mode (ADR 0015). Runs on the live RDS database, so
 * it only adds empty (nullable) columns and one new table: no existing
 * value changes and no ledger entry is touched. Every existing purchase and
 * payout stays "simulated".
 *
 * - credit_purchases: the Stripe Checkout and payment ids, a random token
 *   the webhook finds the purchase by, and the last payment error.
 * - merchants: the Express connected account and its onboarding status.
 * - merchant_payouts: one row per Stripe-funded cleared redemption, with
 *   the purchase that funds it (transfer_group, source_transaction).
 * - stripe_events: every webhook event handled, so a replay changes nothing.
 *   Not a demo table: Reset demo keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_purchases', function (Blueprint $table) {
            $table->string('payment_token', 64)->nullable()->unique();
            $table->string('stripe_checkout_session_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('stripe_charge_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_error')->nullable();
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->string('stripe_account_id')->nullable()->unique();
            $table->string('stripe_onboarding_key', 64)->nullable();
            $table->boolean('stripe_details_submitted')->nullable();
            $table->boolean('stripe_payouts_enabled')->nullable();
            $table->boolean('stripe_transfers_active')->nullable();
            $table->timestamp('stripe_synced_at')->nullable();
        });

        Schema::table('merchant_payouts', function (Blueprint $table) {
            $table->foreignId('redemption_id')->nullable()->unique()->constrained();
            $table->foreignId('credit_purchase_id')->nullable()->constrained();
            $table->string('transfer_group')->nullable();
            $table->string('source_charge_id')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('reversed_at')->nullable();
        });

        Schema::create('stripe_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('type');
            $table->string('outcome')->nullable();
            $table->timestamp('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');

        Schema::table('merchant_payouts', function (Blueprint $table) {
            $table->dropForeign(['redemption_id']);
            $table->dropForeign(['credit_purchase_id']);
            $table->dropUnique(['redemption_id']);
            $table->dropColumn(['redemption_id', 'credit_purchase_id', 'transfer_group', 'source_charge_id', 'idempotency_key', 'failure_reason', 'reversed_at']);
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropUnique(['stripe_account_id']);
            $table->dropColumn(['stripe_account_id', 'stripe_onboarding_key', 'stripe_details_submitted', 'stripe_payouts_enabled', 'stripe_transfers_active', 'stripe_synced_at']);
        });

        Schema::table('credit_purchases', function (Blueprint $table) {
            $table->dropUnique(['payment_token']);
            $table->dropUnique(['stripe_payment_intent_id']);
            $table->dropColumn(['payment_token', 'stripe_checkout_session_id', 'stripe_payment_intent_id', 'stripe_charge_id', 'paid_at', 'payment_error']);
        });
    }
};
