<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The solvency rule (ADR 0033). Additive: one new table, nothing else.
 *
 * solvency_checks: one row per full check of the ledger history (from the
 * command, the demo activity tick, a deploy or the platform admin's "Run
 * the check now"). What was checked (entries, moments, date range), the
 * result, the position at the end and the first windows of time in which
 * the rule failed. Writes nothing to the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solvency_checks', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 16);
            $table->boolean('passed');
            $table->unsignedInteger('entries_checked');
            $table->unsignedInteger('moments_checked');
            $table->timestamp('first_entry_at')->nullable();
            $table->timestamp('last_entry_at')->nullable();
            $table->bigInteger('customer_funds_cents');
            $table->bigInteger('operating_cents');
            $table->bigInteger('in_hand_cents');
            $table->unsignedInteger('failed_moments')->default(0);
            $table->timestamp('first_failure_at')->nullable();
            $table->json('failures')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solvency_checks');
    }
};
