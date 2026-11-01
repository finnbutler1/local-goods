<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two separate Stripe balances (ADR 0034). Additive: two new tables and one
 * nullable column; no existing row changes.
 *
 * - operating_accounts: the Local Goods Operating connected account for
 *   this environment (one row). Kept by Reset demo, like merchants' Stripe
 *   account ids.
 * - operating_sweeps: each recorded move of earned fees from the customer
 *   funds account to Local Goods Operating: amount, reason, who decided,
 *   what they reviewed, and the Stripe transfer (or simulated). open_key is
 *   set only while a sweep is pending, so only one is ever in flight.
 * - credit_ledger_entries.operating_sweep_id: links a sweep's ledger lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operating_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_account_id')->nullable()->unique();
            $table->uuid('setup_key')->unique();
            $table->boolean('transfers_active')->default(false);
            $table->timestamps();
        });

        Schema::create('operating_sweeps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('amount_cents');
            $table->string('channel', 16);
            $table->string('status', 16);
            $table->string('open_key', 32)->nullable()->unique();
            $table->text('reason');
            $table->unsignedBigInteger('reviewed_against_cents');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stripe_transfer_id')->nullable()->unique();
            $table->string('idempotency_key')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamp('swept_at')->nullable();
            $table->timestamps();
        });

        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->foreignId('operating_sweep_id')->nullable()->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('operating_sweep_id');
        });
        Schema::dropIfExists('operating_sweeps');
        Schema::dropIfExists('operating_accounts');
    }
};
