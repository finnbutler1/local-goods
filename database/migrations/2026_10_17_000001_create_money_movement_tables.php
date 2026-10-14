<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The money around the employee wallet: the employer buys credits, hands
 * them to employees, and merchants are paid out for cleared redemptions.
 * Each row here is the record of one movement; the amounts themselves live
 * in the append-only credit ledger (see the next migration). Only creates
 * tables, so it cannot touch existing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employers', function (Blueprint $table) {
            $table->unsignedInteger('staff_count')->nullable();
        });

        Schema::create('credit_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_id')->constrained();
            $table->unsignedInteger('amount_cents');
            $table->string('reference');
            $table->timestamp('purchased_at');
            $table->timestamps();
        });

        Schema::create('credit_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_id')->constrained();
            $table->foreignId('employee_id')->constrained();
            $table->unsignedInteger('amount_cents');
            $table->timestamp('allocated_at');
            $table->timestamps();
        });

        Schema::create('merchant_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained();
            $table->unsignedInteger('amount_cents');
            $table->string('status'); // pending | sent | failed | simulated
            $table->string('stripe_transfer_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_payouts');
        Schema::dropIfExists('credit_allocations');
        Schema::dropIfExists('credit_purchases');

        Schema::table('employers', function (Blueprint $table) {
            $table->dropColumn('staff_count');
        });
    }
};
