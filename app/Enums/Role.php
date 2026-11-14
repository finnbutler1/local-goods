<?php

namespace App\Enums;

/**
 * Each user has exactly one role, stored in users.role.
 */
enum Role: string
{
    case PlatformAdmin = 'platform_admin';
    case BidAdmin = 'bid_admin';
    case EmployerAdmin = 'employer_admin';
    case Employee = 'employee';
    case Merchant = 'merchant';
    case Judge = 'judge';

    public function label(): string
    {
        return match ($this) {
            self::PlatformAdmin => 'Platform admin',
            self::BidAdmin => 'BID admin',
            self::EmployerAdmin => 'Employer admin',
            self::Employee => 'Employee',
            self::Merchant => 'Merchant',
            self::Judge => 'Judge',
        };
    }

    /** Where this role lands after logging in. */
    public function dashboardRoute(): string
    {
        return match ($this) {
            self::PlatformAdmin => 'admin.dashboard',
            self::BidAdmin => 'bid-admin.home',
            self::EmployerAdmin => 'employer.dashboard',
            self::Employee => 'employee.wallet',
            self::Merchant => 'merchant.dashboard',
            self::Judge => 'judge.dashboard',
        };
    }

    /** The admin roles get a grouped menu; everyone else a short flat list. */
    public function hasGroupedNavigation(): bool
    {
        return $this === self::PlatformAdmin || $this === self::BidAdmin;
    }

    /**
     * The menu as headed groups (a group with a null label is shown as plain
     * links). Sidebar and phone Menu sheet both read this. Labels are Title Case.
     * The BID admin sees only the pages they can open; judges viewing as the
     * BID admin get the same structure (they can open the same pages, read-only).
     *
     * @return array<string, array{label: ?string, items: array<string, array{label: string, short: string, route: string, icon: string}>}>
     */
    public function navigationGroups(): array
    {
        if (! $this->hasGroupedNavigation()) {
            return ['main' => ['label' => null, 'items' => $this->flatNavigation()]];
        }

        $platform = $this === self::PlatformAdmin;
        $i = self::icons();
        $item = fn (string $label, string $short, string $route, string $icon) => compact('label', 'short', 'route', 'icon');

        $groups = [
            'home' => ['label' => null, 'items' => [
                'home' => $item('Home', 'Home', $this->dashboardRoute(), $i['home']),
            ]],
            'reviews' => ['label' => 'Reviews & Decisions', 'items' => [
                'fraud-review' => $item('Fraud Review', 'Review', 'bid-admin.dashboard', $i['shield']),
                'corrections' => $item('Corrections', 'Fixes', 'bid-admin.corrections', $i['scales']),
                'applications' => $item('Merchant Applications', 'Apply', 'bid-admin.applications', $i['inbox']),
                'merchants' => $item('Merchant Eligibility', 'Merchants', 'bid-admin.merchants', $i['store']),
            ]],
            'money' => ['label' => 'Money', 'items' => array_filter([
                'money' => $item('Where the Money Is', 'Money', 'money', $i['ledger']),
                'reconciliation' => $platform ? $item('Reconciliation', 'Stripe', 'admin.reconciliation', $i['scales']) : null,
                // Platform admin only in the menu. The BID admin still opens these pages
                // by role (Demo Activity from the Home card, Fee Settings by address).
                'fees' => $platform ? $item('Fee Settings', 'Fees', 'bid-admin.fees', $i['ledger']) : null,
                'demo-activity' => $platform ? $item('Demo Activity', 'Activity', 'bid-admin.demo-activity', $i['trend']) : null,
            ])],
            'program' => ['label' => 'Program', 'items' => array_filter([
                'impact' => $item('Impact', 'Impact', 'bid-admin.impact', $i['trend']),
                'participation' => $item('Participation', 'Program', 'bid-admin.participation', $i['people']),
                'partnership' => $item('Partnership', 'Partner', 'bid-admin.partnership', $i['handshake']),
                'program-fee' => $item('Program Fee', 'Fee', 'bid-admin.program-fee', $i['ledger']),
                'district' => $item('District Map', 'Map', 'district', $i['store']),
                'business-model' => $platform ? $item('Business Model', 'Model', 'business-model', $i['ledger']) : null,
            ])],
            'ai' => ['label' => 'AI & Evaluation', 'items' => array_filter([
                'eval' => $item('AI Eval', 'AI Eval', 'bid-admin.eval', $i['chart']),
                // Platform admin only; the BID admin reaches it from Merchant Eligibility and /eval.
                'merchant-eval' => $platform ? $item('Merchant Eval', 'Eval', 'eval.merchants', $i['chart']) : null,
            ])],
        ];

        if ($platform) {
            $groups['people'] = ['label' => 'People & Access', 'items' => [
                'reviewers' => $item('Reviewers', 'Reviewers', 'admin.reviewers.index', $i['people']),
                'accounts' => $item('Accounts', 'Accounts', 'admin.accounts', $i['people']),
                'feedback' => $item('Feedback', 'Feedback', 'admin.feedback.index', $i['inbox']),
                'changelog' => $item('Changelog', 'Changelog', 'admin.changelog.index', $i['inbox']),
            ]];
        }

        // A group holding one page needs no header: show that page on its own
        // (the BID admin's Money and AI & Evaluation after the 2026-11-12 trim).
        foreach ($groups as $key => $group) {
            if ($group['label'] !== null && count($group['items']) === 1) {
                $groups[$key]['label'] = null;
            }
        }

        return $groups;
    }

    /** The two pages shown beside Home on the phone bar (the rest are under Menu). */
    public function pinnedNavigation(): array
    {
        return match ($this) {
            self::PlatformAdmin => ['fraud-review', 'money'],
            self::BidAdmin => ['fraud-review', 'impact'],
            default => [],
        };
    }

    /**
     * Every menu item as one list, keyed by item. The sidebar and the phone
     * Menu show navigationGroups(); this is for roles with a flat menu and
     * for tests.
     *
     * @return array<string, array{label: string, short: string, route: string, icon: string}>
     */
    public function navigation(): array
    {
        if (! $this->hasGroupedNavigation()) {
            return $this->flatNavigation();
        }

        return array_merge(...array_map(fn ($group) => $group['items'], array_values($this->navigationGroups())));
    }

    /** @return array<string, string> */
    private static function icons(): array
    {
        return [
            'home' => 'M3.75 12 12 4.5l8.25 7.5M5.25 10.5v9h4.5v-5.25h4.5v5.25h4.5v-9',
            'wallet' => 'M3.75 7.5h15a1.5 1.5 0 0 1 1.5 1.5v9a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 2.25 18V6a1.5 1.5 0 0 1 1.5-1.5h12M16.5 13.5h.01',
            'shield' => 'M12 3 4.5 6v5.25c0 4.5 3.2 8.4 7.5 9.75 4.3-1.35 7.5-5.25 7.5-9.75V6L12 3Zm-3 9 2.25 2.25L15 10.5',
            'chart' => 'M4.5 19.5h15M7.5 16.5v-4.5M12 16.5V7.5M16.5 16.5v-7.5',
            'inbox' => 'M2.25 13.5h4.5l1.5 2.25h7.5l1.5-2.25h4.5M4.5 6.75 2.25 13.5v4.5a1.5 1.5 0 0 0 1.5 1.5h16.5a1.5 1.5 0 0 0 1.5-1.5v-4.5L19.5 6.75a1.5 1.5 0 0 0-1.42-1H5.92a1.5 1.5 0 0 0-1.42 1Z',
            'people' => 'M15 19.5v-1.5a3.75 3.75 0 0 0-3.75-3.75h-4.5A3.75 3.75 0 0 0 3 18v1.5M9 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm12 9v-1.5a3.75 3.75 0 0 0-2.81-3.63M15.75 4.6a3 3 0 0 1 0 5.8',
            'scales' => 'M12 3.75v16.5M7.5 20.25h9M4.5 7.5h15M6.75 7.5 3.75 13.5a3 3 0 0 0 6 0L6.75 7.5Zm10.5 0-3 6a3 3 0 0 0 6 0l-3-6Z',
            'store' => 'M3.75 9.75 5.25 4.5h13.5l1.5 5.25M3.75 9.75a2.25 2.25 0 0 0 4.5 0 2.25 2.25 0 0 0 4.5 0 2.25 2.25 0 0 0 4.5 0 2.25 2.25 0 0 0 4.5 0M5.25 12v7.5h13.5V12M9.75 19.5v-4.5h4.5v4.5',
            'trend' => 'M3.75 17.25 9 12l3.75 3.75 7.5-7.5M15 8.25h5.25v5.25',
            'ledger' => 'M3.75 4.5h16.5v15H3.75v-15ZM3.75 9h16.5M9 9v10.5M12.75 12.75h4.5M12.75 15.75h4.5',
            'handshake' => 'M3.75 12h3l3-3 3 1.5 3-3h4.5M3.75 12l4.5 4.5 1.5-1.5 1.5 1.5 1.5-1.5 1.5 1.5 3.75-3.75M20.25 7.5v6',
        ];
    }

    /** @return array<string, array{label: string, short: string, route: string, icon: string}> */
    private function flatNavigation(): array
    {
        $i = self::icons();
        $dashboard = ['label' => 'Dashboard', 'short' => 'Home', 'route' => $this->dashboardRoute(), 'icon' => $i['home']];
        $businessModel = ['label' => 'Business Model', 'short' => 'Model', 'route' => 'business-model', 'icon' => $i['ledger']];

        return match ($this) {
            self::Employee => ['wallet' => ['label' => 'My Wallet', 'short' => 'Wallet', 'route' => 'employee.wallet', 'icon' => $i['wallet']]],
            self::Merchant => ['dashboard' => $dashboard, 'menu' => ['label' => 'Menu', 'short' => 'Menu', 'route' => 'merchant.menu', 'icon' => $i['store']]],
            self::Judge => [
                'dashboard' => $dashboard,
                'business-model' => $businessModel,
                'district' => ['label' => 'District Map', 'short' => 'Map', 'route' => 'district', 'icon' => $i['store']],
                'merchant-eval' => ['label' => 'Merchant Eval', 'short' => 'Eval', 'route' => 'eval.merchants', 'icon' => $i['chart']],
            ],
            self::EmployerAdmin => ['dashboard' => $dashboard],
            default => [],
        };
    }
}
