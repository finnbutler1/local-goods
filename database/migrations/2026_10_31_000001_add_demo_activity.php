<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live demo activity (ADR 0032). Additive: two new tables, nothing else.
 *
 * - demo_activity_days: one row per weekday the generator ran (date is
 *   unique), the plan it drew for that date, why it stopped early if it
 *   did, and the last reconciliation result.
 * - demo_activity_actions: one row per thing it did or tried, claimed
 *   before it runs (day_id + slot is unique, so a second or overlapping run
 *   never does a slot twice). It points at the purchase, redemption or
 *   correction it made through the normal services, which is how pages tell
 *   generated activity from what a person did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_activity_days', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('seed');
            $table->json('plan');
            $table->string('halted_reason')->nullable();
            $table->unsignedTinyInteger('reconciliation_misses')->default(0);
            $table->json('last_reconciliation')->nullable();
            $table->timestamps();
        });

        Schema::create('demo_activity_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('day_id')->constrained('demo_activity_days')->cascadeOnDelete();
            $table->string('slot', 40);
            $table->string('kind', 24);
            $table->string('status', 16);
            $table->timestamp('planned_for')->nullable();
            $table->timestamp('ran_at')->nullable();
            $table->unsignedInteger('amount_cents')->default(0);
            $table->boolean('unusual')->default(false);
            $table->string('channel', 16)->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained('merchants')->nullOnDelete();
            $table->foreignId('redemption_id')->nullable()->constrained('redemptions')->nullOnDelete();
            $table->foreignId('credit_purchase_id')->nullable()->constrained('credit_purchases')->nullOnDelete();
            $table->foreignId('correction_id')->nullable()->constrained('corrections')->nullOnDelete();
            $table->string('note')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['day_id', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_activity_actions');
        Schema::dropIfExists('demo_activity_days');
    }
};
