<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback left from the Feedback button on any signed-in page, scored
 * against the contest rubric, and triaged by the platform admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('page_url', 2048);
            $table->string('category')->index();
            $table->unsignedTinyInteger('rating');
            $table->string('severity')->index();
            $table->text('comment');
            $table->string('status')->default('new')->index();
            $table->string('fixed_commit_sha', 40)->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
