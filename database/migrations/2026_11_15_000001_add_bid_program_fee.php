<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The BID annual program fee, charged in Stripe test mode (ADR 0044).
 * Additive only: two empty columns on districts, two new tables and one
 * empty column on the ledger. No row is written or changed, so no money,
 * ledger line or balance moves.
 *
 * - districts.member_businesses, districts.program_starts_on: set by a
 *   platform admin (DistrictProgramSettings), empty until then. The tier
 *   comes from the member count; program years count from the start date.
 * - district_program_changes: append-only audit of every change to those
 *   two columns (who, when, old → new, reason), like merchant_fee_changes.
 * - bid_program_fee_payments: one row per attempt to pay a program year.
 *   fee_key is "<district id>:<program year>" while the row is pending or
 *   paid; unique, so a district pays a program year at most once.
 * - credit_ledger_entries.bid_program_fee_payment_id: links the fee's
 *   platform ledger line to its payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('districts', function (Blueprint $table) {
            $table->unsignedInteger('member_businesses')->nullable();
            $table->date('program_starts_on')->nullable();
        });

        Schema::create('district_program_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained();
            $table->unsignedInteger('member_businesses');
            $table->unsignedInteger('previous_member_businesses')->nullable();
            $table->date('program_starts_on');
            $table->date('previous_program_starts_on')->nullable();
            $table->text('reason');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bid_program_fee_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained();
            $table->unsignedSmallInteger('program_year');
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->string('tier', 20);
            $table->unsignedInteger('member_businesses');
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->string('fee_key', 40)->nullable()->unique();
            $table->uuid('payment_token')->unique();
            $table->string('stripe_checkout_session_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('stripe_charge_id')->nullable();
            $table->string('payment_error')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('admin_note_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('admin_noted_at')->nullable();
            $table->timestamps();
        });

        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->foreignId('bid_program_fee_payment_id')->nullable()->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bid_program_fee_payment_id');
        });
        Schema::dropIfExists('bid_program_fee_payments');
        Schema::dropIfExists('district_program_changes');
        Schema::table('districts', function (Blueprint $table) {
            $table->dropColumn(['member_businesses', 'program_starts_on']);
        });
    }
};
