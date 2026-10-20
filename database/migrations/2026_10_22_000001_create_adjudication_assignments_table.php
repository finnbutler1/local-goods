<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blind adjudication in batches of 10 (ADR 0020): which cases each
 * adjudicator was given, in which batch and order, and when that batch was
 * submitted. Only adds a table; existing sets, cases and votes are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adjudication_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjudication_participant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('adjudication_case_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('batch');
            $table->unsignedSmallInteger('position');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            // Never the same case twice for one person.
            $table->unique(['adjudication_participant_id', 'adjudication_case_id'], 'adjudication_assignments_participant_case_unique');
            $table->index(['adjudication_participant_id', 'batch'], 'adjudication_assignments_participant_batch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjudication_assignments');
    }
};
