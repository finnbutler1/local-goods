<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bids', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('city');
            $table->timestamps();
        });

        Schema::create('employers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_id')->constrained();
            $table->string('name');
            $table->unsignedInteger('monthly_allowance_cents');
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_id')->constrained();
            $table->string('display_name');
            $table->timestamps();
        });

        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_id')->constrained();
            $table->string('name');
            $table->string('category');
            $table->string('address');
            $table->timestamps();
        });

        Schema::create('redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->foreignId('merchant_id')->constrained();
            $table->unsignedInteger('amount_cents');
            $table->string('status'); // approved | under_review
            $table->unsignedTinyInteger('risk_score')->nullable();
            $table->string('ai_decision')->nullable(); // clear | hold
            $table->text('ai_explanation')->nullable();
            $table->json('signals')->nullable();
            $table->string('scored_by'); // model id, "fallback", or "seed"
            $table->timestamps();
        });

        // Append-only: rows are never updated or deleted. Balance = SUM(amount_cents).
        Schema::create('credit_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->integer('amount_cents'); // positive = credit, negative = debit
            $table->string('type'); // allowance | redemption
            $table->foreignId('redemption_id')->nullable()->constrained();
            $table->string('description');
            $table->timestamp('created_at');
            $table->index(['employee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger_entries');
        Schema::dropIfExists('redemptions');
        Schema::dropIfExists('merchants');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('employers');
        Schema::dropIfExists('bids');
    }
};
