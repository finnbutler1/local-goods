<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blind adjudication of the fraud eval (ADR 0011): named BID and contest-judge
 * reviewers vote on the cases where the person's label and the AI disagree,
 * mixed with agreed cases as blind controls. Never touches the eval labels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adjudication_sets', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->string('status')->default('draft')->index();
            $table->string('source_run')->nullable();
            $table->string('prompt_version', 16)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('adjudication_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjudication_set_id')->constrained()->cascadeOnDelete();
            $table->string('case_id', 16);
            $table->string('kind', 16);
            $table->string('split', 16);
            $table->string('my_label', 16);
            $table->string('ai_label', 16);
            $table->json('tags');
            $table->json('facts');
            $table->timestamps();
            $table->unique(['adjudication_set_id', 'case_id']);
        });

        Schema::create('adjudication_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjudication_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('seen_eval_before');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['adjudication_set_id', 'user_id']);
        });

        Schema::create('adjudication_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjudication_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 16);
            $table->text('reason');
            $table->timestamps();
            $table->unique(['adjudication_case_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjudication_votes');
        Schema::dropIfExists('adjudication_participants');
        Schema::dropIfExists('adjudication_cases');
        Schema::dropIfExists('adjudication_sets');
    }
};
