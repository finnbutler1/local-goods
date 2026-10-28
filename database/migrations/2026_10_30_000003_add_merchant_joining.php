<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merchant joining (ADR 0031). Additive: new tables only.
 *
 * - address_lookups: answers from DC's Master Address Repository, so an
 *   address is looked up once. origin is "checker" (the public district
 *   map) or "application". A checker row never keeps the typed text: only
 *   its SHA-256 and the answer, and it is purged after 30 days
 *   (applications:purge). An application row keeps its query.
 * - merchant_applications: a shop's request to join, through
 *   applied → eligibility_checked → bid_approved (or declined) →
 *   stripe_onboarding → live. Only MerchantApplications changes the
 *   status. The applicant's private status link is stored as a SHA-256
 *   only. The AI's eligibility suggestion is kept on the row; it never
 *   decides. merchant_id is set once the shop is a merchant.
 * - merchant_application_events: every step, who, when and why
 *   (append-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('address_lookups', function (Blueprint $table) {
            $table->id();
            $table->string('origin', 16);
            $table->char('query_hash', 64);
            $table->string('query', 255)->nullable();
            $table->string('status', 16);
            $table->string('matched_address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedBigInteger('mar_address_id')->nullable();
            $table->string('source', 16)->default('dc_mar');
            $table->timestamp('looked_up_at');
            $table->unique(['origin', 'query_hash']);
            $table->index('looked_up_at');
        });

        Schema::create('merchant_applications', function (Blueprint $table) {
            $table->id();
            $table->string('business_name', 120);
            $table->string('address', 200);
            $table->string('category', 20);
            $table->string('contact_email', 254);
            $table->text('independence_answer');
            $table->string('status', 24)->default('applied');
            $table->char('status_token_hash', 64)->unique();
            $table->timestamp('prototype_acknowledged_at')->nullable();
            $table->foreignId('district_id')->constrained('districts');
            $table->string('matched_address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('inside_district')->nullable();
            $table->boolean('ai_eligible')->nullable();
            $table->string('ai_classification', 20)->nullable();
            $table->decimal('ai_confidence', 4, 3)->nullable();
            $table->text('ai_reasoning')->nullable();
            $table->boolean('ai_needs_review')->nullable();
            $table->string('ai_prompt_version', 16)->nullable();
            $table->string('ai_model', 64)->nullable();
            $table->text('decline_reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained('merchants')->nullOnDelete();
            $table->boolean('is_sample')->default(false);
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('merchant_application_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_application_id')->constrained('merchant_applications')->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('actor', 16);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_application_events');
        Schema::dropIfExists('merchant_applications');
        Schema::dropIfExists('address_lookups');
    }
};
