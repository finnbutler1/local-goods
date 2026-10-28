<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BID partnerships (ADR 0031). Additive: two new tables, nothing else.
 *
 * - partnerships: the BID's agreement for a district: exclusive or not,
 *   dates, revenue share in basis points (0 by default; display only, no
 *   money moves), data terms and status (draft, active, ended).
 *   exclusive_active_key is the district id while the partnership is
 *   exclusive and active and NULL otherwise. It is unique, so the database
 *   itself refuses a second active exclusive partnership in one district
 *   (many NULLs are allowed on SQLite and MySQL alike).
 * - partnership_changes: every change, who, when, why, before and after
 *   (append-only, like merchant_fee_changes). The newest id is what the
 *   platform admin reviewed against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partnerships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_id')->constrained('bids');
            $table->foreignId('district_id')->constrained('districts');
            $table->boolean('exclusive')->default(false);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('revenue_share_bps')->default(0);
            $table->text('data_terms');
            $table->string('status', 16)->default('draft');
            $table->boolean('is_demo')->default(false);
            $table->unsignedBigInteger('exclusive_active_key')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('partnership_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partnership_id')->constrained('partnerships')->cascadeOnDelete();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partnership_changes');
        Schema::dropIfExists('partnerships');
    }
};
