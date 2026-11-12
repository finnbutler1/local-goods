<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employer platform fee per participating employee (ADR 0042). Built
 * and tested; switched off during the pilot. Additive: five columns on
 * credit_purchases, all 0 or empty on every existing row, so no purchase,
 * ledger line or balance changes.
 *
 * - employer_fee_cents: the fee on this purchase (employees × unit), 0 for none.
 * - employer_fee_employees, employer_fee_unit_cents: how it was worked out,
 *   copied when the purchase was requested.
 * - employer_fee_month: the month it pays for ("2026-11"), DC time.
 * - employer_fee_key: "<employer id>:<month>" while the purchase is pending
 *   or paid, cleared if it is cancelled. Unique, so an employer pays the fee
 *   at most once a month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_purchases', function (Blueprint $table) {
            $table->unsignedInteger('employer_fee_cents')->default(0);
            $table->unsignedInteger('employer_fee_employees')->default(0);
            $table->unsignedInteger('employer_fee_unit_cents')->default(0);
            $table->string('employer_fee_month', 7)->nullable();
            $table->string('employer_fee_key', 40)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('credit_purchases', function (Blueprint $table) {
            $table->dropUnique(['employer_fee_key']);
            $table->dropColumn(['employer_fee_cents', 'employer_fee_employees', 'employer_fee_unit_cents', 'employer_fee_month', 'employer_fee_key']);
        });
    }
};
