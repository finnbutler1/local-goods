<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds and corrections (ADR 0028). Runs on the live RDS database, so it
 * only adds: no existing amount or ledger entry changes, and no money row
 * is created.
 *
 * - corrections: a request to fix a money mistake (a redemption refund to
 *   the employee, a merchant adjustment, or an employer refund of unspent
 *   credits) and the platform admin's decision on it. open_key is set only
 *   while the request is open, and is unique, so the database itself allows
 *   at most one open request per item.
 * - correction_items: which menu items (and how many) a refund covers.
 * - correction_actions: each Stripe action a correction takes (a transfer
 *   reversal, a card refund, sending an owed transfer) or a simulated one,
 *   with its own idempotency key.
 * - credit_ledger_entries.correction_id: links a correction's ledger lines
 *   to it. Null on every existing entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corrections', function (Blueprint $table) {
            $table->id();
            $table->string('type', 24);
            $table->string('status', 16);
            $table->string('open_key', 64)->nullable()->unique();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_role', 24);
            $table->foreignId('redemption_id')->nullable()->constrained();
            $table->foreignId('merchant_payout_id')->nullable()->constrained();
            $table->foreignId('employer_id')->nullable()->constrained();
            $table->foreignId('merchant_id')->nullable()->constrained();
            $table->foreignId('employee_id')->nullable()->constrained();
            $table->string('reason_category', 32);
            $table->text('note')->nullable();
            $table->unsignedInteger('amount_cents')->nullable();
            $table->unsignedInteger('subtotal_cents')->nullable();
            $table->unsignedInteger('tax_cents')->nullable();
            $table->json('tax_lines')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });

        Schema::create('correction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('correction_id')->constrained()->cascadeOnDelete();
            // Indexed, not a foreign key: MySQL would then refuse to drop
            // redemption_items, which the menu migration's own rollback
            // (and its safety test) does. Rows only ever point at items of
            // the correction's own redemption (CorrectionLimits checks).
            $table->unsignedBigInteger('redemption_item_id')->index();
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('amount_cents');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('correction_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('correction_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->foreignId('credit_purchase_id')->nullable()->constrained();
            $table->foreignId('merchant_payout_id')->nullable()->constrained();
            $table->unsignedInteger('amount_cents');
            $table->string('status', 16);
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->string('stripe_id', 64)->nullable()->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->foreignId('correction_id')->nullable()->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('correction_id');
        });

        Schema::dropIfExists('correction_actions');
        Schema::dropIfExists('correction_items');
        Schema::dropIfExists('corrections');
    }
};
