<?php

use App\Support\RetiredDemoAccounts;
use Illuminate\Database\Migrations\Migration;

/**
 * One-time cleanup: the old "Casey Brooks" demo judge was replaced by Public
 * judge access (ADR 0010). It is deleted for good if nothing points at it,
 * otherwise kept but revoked. demo:seed-accounts never recreates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        RetiredDemoAccounts::cleanUp();
    }

    public function down(): void
    {
        // A deleted demo login isn't restored.
    }
};
