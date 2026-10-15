<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only log of BID decisions on real merchant candidates
 * (Merchant Eligibility Matching review queue). A candidate's status is its
 * latest row; a change of mind is a new row. Only creates a table, so it
 * cannot touch existing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_review_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('candidate_id', 16)->index();
            $table->string('decision', 20); // approve | reject | needs_research
            $table->text('note')->nullable();
            $table->foreignId('user_id')->constrained();
            // Which AI answer the person was looking at.
            $table->string('ai_classification', 20)->nullable();
            $table->string('ai_prompt_version', 16)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_review_decisions');
    }
};
