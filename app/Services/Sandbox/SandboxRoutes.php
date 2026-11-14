<?php

namespace App\Services\Sandbox;

use App\Enums\Role;
use Illuminate\Support\Str;

/**
 * What a sandbox can open (ADR 0045). Only the money loop: buy, give, pay,
 * see the payout and the impact, review a held payment. Everything else is a
 * 404 inside a sandbox, including every /admin page (reviewer names, Stripe
 * reconciliation, sweeps, fee settings, the public judge link), Your account,
 * sign-in pages, feedback and the evals. Route names, exact or "prefix.*".
 */
final class SandboxRoutes
{
    public const ALLOWED = [
        'sandbox.*',
        // Employer (HR)
        'employer.dashboard',
        'employer.program.update',
        'employer.purchases.store',
        'employer.purchases.pay',
        'employer.purchases.status',
        'employer.purchases.cancel',
        'employer.allocations.store',
        'employer.correction.store',
        // Employee
        'employee.wallet',
        'employee.demo-tools',
        'employee.redeem',
        'employee.pay-code.*',
        'employee.correction.store',
        // Merchant
        'merchant.dashboard',
        'merchant.menu',
        'merchant.menu.store',
        'merchant.menu.update',
        'merchant.redemptions.csv',
        'merchant.pay-code.*',
        'merchant.correction.*',
        'merchant.redemption.cancel',
        // BID admin and the Local Goods reviewer
        'bid-admin.home',
        'bid-admin.dashboard',
        'bid-admin.approve',
        'bid-admin.deny',
        'bid-admin.collusion-example',
        'bid-admin.impact',
        'bid-admin.participation',
        'bid-admin.corrections',
        'bid-admin.corrections.*',
        'money',
    ];

    /**
     * The role bar: key => [label, role, where it lands].
     *
     * @var array<string, array{0: string, 1: Role, 2: string}>
     */
    public const ROLES = [
        'employer' => ['Employer (HR)', Role::EmployerAdmin, 'employer.dashboard'],
        'employee' => ['Employee', Role::Employee, 'employee.wallet'],
        'merchant' => ['Merchant', Role::Merchant, 'merchant.dashboard'],
        'bid_admin' => ['BID admin', Role::BidAdmin, 'bid-admin.home'],
        'reviewer' => ['Local Goods reviewer', Role::PlatformAdmin, 'bid-admin.dashboard'],
    ];

    /**
     * The "Try this" checklist: [what to do, how, role key, page it opens].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public const CHECKLIST = [
        ['Buy credits', 'Under Buy credits, buy up to $5,000 of credits. The payment is simulated: no card is charged and Stripe is not contacted.', 'employer', 'employer.dashboard'],
        ['Give credits to an employee', 'Under Give credits, give some to Jordan Rivera.', 'employer', 'employer.dashboard'],
        ['Pay at a merchant', 'Spend credits at Farragut Grind: pick items from its menu, or get a pay code to pay in person.', 'employee', 'employee.wallet'],
        ['See the merchant\'s payout', 'The sale shows under recent payments once the fraud check clears it, with its simulated payout.', 'merchant', 'merchant.dashboard'],
        ['See the district\'s impact', 'Spending at independent merchants in the district, counted from the ledger.', 'bid_admin', 'bid-admin.impact'],
        ['Try a suspicious payment', 'Press "Try a suspicious redemption", then confirm it on the wallet. The AI usually holds it, but it may clear it.', 'employee', 'employee.demo-tools'],
        ['Decide a held payment', 'In Fraud Review, approve or deny the held payment, with a reason. Approving pays the merchant (simulated).', 'reviewer', 'bid-admin.dashboard'],
    ];

    public static function allows(?string $routeName): bool
    {
        if ($routeName === null) {
            return false;
        }

        foreach (self::ALLOWED as $pattern) {
            if (Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }

    /** The sandbox login for a role key: e.g. employer@sandbox.invalid. */
    public static function email(string $key): string
    {
        return $key.'@'.config('sandbox.email_domain');
    }

    public static function keyForRole(?Role $role): ?string
    {
        foreach (self::ROLES as $key => [, $r]) {
            if ($r === $role) {
                return $key;
            }
        }

        return null;
    }
}
