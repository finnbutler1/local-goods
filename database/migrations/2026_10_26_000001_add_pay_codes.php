<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pay in person (ADR 0026). Runs on the live RDS database, so it only adds:
 * no existing value or ledger entry changes.
 *
 * - pay_codes: a one-time code an employee shows at the counter, tied to one
 *   employee, one merchant and one amount, for 10 minutes. Found by an HMAC
 *   of the code (code_hash); the code itself is stored encrypted so the
 *   employee's phone can show it again after a reload. Nothing moves until
 *   the merchant confirms; then redemption_id points at the redemption.
 * - redemptions.channel: "to_go" or "in_person". Display only, never sent to
 *   Redemption Fraud Detection. Every existing row is "to_go".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->json('items')->nullable();
            $table->char('code_hash', 64)->index();
            $table->text('code');
            $table->string('status', 16);
            $table->string('cancelled_by', 16)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('redemption_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });

        Schema::table('redemptions', function (Blueprint $table) {
            $table->string('channel', 16)->default('to_go');
        });
    }

    public function down(): void
    {
        Schema::table('redemptions', function (Blueprint $table) {
            $table->dropColumn('channel');
        });

        Schema::dropIfExists('pay_codes');
    }
};
