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
    }

    public function down(): void
    {
        Schema::dropIfExists('address_lookups');
    }
};
