<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The fraud gate on transfers (ADR 0016). Runs on the live RDS database, so
 * it only adds: one new table, one column with a default, and a backfilled
 * row for each redemption a person already decided. No existing value
 * changes and no ledger entry is touched.
 *
 * - redemption_decisions: a person's Approve or Deny on a held redemption,
 *   append-only, at most one per redemption (the unique index refuses a
 *   second). Keeps who, when, the reason, where denied credit went and a
 *   copy of what the AI originally said. Never an eval label.
 * - merchant_payouts.attempts: how many times Stripe definitely refused
 *   this row's transfer; part of its idempotency key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redemption_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('redemption_id')->unique()->constrained();
            $table->string('decision', 16);
            $table->string('reason', 500);
            $table->string('credit_returned_to', 16)->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name');
            $table->string('decided_by_role', 32)->nullable();
            $table->string('ai_decision', 16)->nullable();
            $table->unsignedTinyInteger('ai_risk_score')->nullable();
            $table->string('ai_scored_by')->nullable();
            $table->boolean('backfilled')->default(false);
            $table->timestamp('decided_at');
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('merchant_payouts', function (Blueprint $table) {
            $table->unsignedInteger('attempts')->default(0);
        });

        // Decisions made before reasons were recorded: one row each, from the
        // redemption itself, so every decided redemption has a decision row.
        $now = now();
        DB::table('redemptions')->whereNotNull('reviewed_at')->whereIn('status', ['approved', 'rejected'])
            ->orderBy('id')->get()
            ->each(fn ($r) => DB::table('redemption_decisions')->insert([
                'redemption_id' => $r->id,
                'decision' => $r->status === 'approved' ? 'approve' : 'deny',
                'reason' => 'Decided before reasons were recorded.',
                'credit_returned_to' => $r->status === 'rejected' ? 'employee' : null,
                'decided_by_user_id' => DB::table('users')->where('id', $r->reviewed_by_user_id)->exists() ? $r->reviewed_by_user_id : null,
                'decided_by_name' => $r->reviewed_by ?? 'Unknown reviewer',
                'decided_by_role' => null,
                'ai_decision' => $r->ai_decision,
                'ai_risk_score' => $r->risk_score,
                'ai_scored_by' => $r->scored_by,
                'backfilled' => true,
                'decided_at' => $r->reviewed_at,
                'created_at' => $now,
            ]));
    }

    public function down(): void
    {
        Schema::table('merchant_payouts', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });

        Schema::dropIfExists('redemption_decisions');
    }
};
