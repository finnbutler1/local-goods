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
            self::BidAdmin => 'bid-admin.dashboard',
            self::EmployerAdmin => 'employer.dashboard',
            self::Employee => 'employee.wallet',
            self::Merchant => 'merchant.dashboard',
            self::Judge => 'judge.dashboard',
        };
    }

    /**
     * Sidebar and mobile tab bar items for this role. The shell adds an
     * unread count to the platform admin's Feedback item.
     *
     * @return array<string, array{label: string, short: string, route: string, icon: string}>
     */
    public function navigation(): array
    {
        $home = 'M3.75 12 12 4.5l8.25 7.5M5.25 10.5v9h4.5v-5.25h4.5v5.25h4.5v-9';
        $wallet = 'M3.75 7.5h15a1.5 1.5 0 0 1 1.5 1.5v9a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 2.25 18V6a1.5 1.5 0 0 1 1.5-1.5h12M16.5 13.5h.01';
        $shield = 'M12 3 4.5 6v5.25c0 4.5 3.2 8.4 7.5 9.75 4.3-1.35 7.5-5.25 7.5-9.75V6L12 3Zm-3 9 2.25 2.25L15 10.5';
        $chart = 'M4.5 19.5h15M7.5 16.5v-4.5M12 16.5V7.5M16.5 16.5v-7.5';
        $inbox = 'M2.25 13.5h4.5l1.5 2.25h7.5l1.5-2.25h4.5M4.5 6.75 2.25 13.5v4.5a1.5 1.5 0 0 0 1.5 1.5h16.5a1.5 1.5 0 0 0 1.5-1.5v-4.5L19.5 6.75a1.5 1.5 0 0 0-1.42-1H5.92a1.5 1.5 0 0 0-1.42 1Z';
        $people = 'M15 19.5v-1.5a3.75 3.75 0 0 0-3.75-3.75h-4.5A3.75 3.75 0 0 0 3 18v1.5M9 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm12 9v-1.5a3.75 3.75 0 0 0-2.81-3.63M15.75 4.6a3 3 0 0 1 0 5.8';

        $scales = 'M12 3.75v16.5M7.5 20.25h9M4.5 7.5h15M6.75 7.5 3.75 13.5a3 3 0 0 0 6 0L6.75 7.5Zm10.5 0-3 6a3 3 0 0 0 6 0l-3-6Z';

        $fraudReview = ['label' => 'Fraud review', 'short' => 'Review', 'route' => 'bid-admin.dashboard', 'icon' => $shield];
        $store = 'M3.75 9.75 5.25 4.5h13.5l1.5 5.25M3.75 9.75a2.25 2.25 0 0 0 4.5 0 2.25 2.25 0 0 0 4.5 0 2.25 2.25 0 0 0 4.5 0 2.25 2.25 0 0 0 4.5 0M5.25 12v7.5h13.5V12M9.75 19.5v-4.5h4.5v4.5';
        $merchants = ['label' => 'Merchant eligibility', 'short' => 'Merchants', 'route' => 'bid-admin.merchants', 'icon' => $store];
        $eval = ['label' => 'AI eval', 'short' => 'AI eval', 'route' => 'bid-admin.eval', 'icon' => $chart];
        $participation = ['label' => 'Participation', 'short' => 'Program', 'route' => 'bid-admin.participation', 'icon' => $people];
        $trend = 'M3.75 17.25 9 12l3.75 3.75 7.5-7.5M15 8.25h5.25v5.25';
        $impact = ['label' => 'Impact', 'short' => 'Impact', 'route' => 'bid-admin.impact', 'icon' => $trend];
        $dashboard = ['label' => 'Dashboard', 'short' => 'Home', 'route' => $this->dashboardRoute(), 'icon' => $home];

        return match ($this) {
            self::PlatformAdmin => [
                'dashboard' => $dashboard,
                'bid-admin' => $fraudReview,
                'eval' => $eval,
                'reviewers' => ['label' => 'Reviewers', 'short' => 'Reviewers', 'route' => 'admin.reviewers.index', 'icon' => $people],
                'feedback' => ['label' => 'Feedback', 'short' => 'Feedback', 'route' => 'admin.feedback.index', 'icon' => $inbox],
                'adjudication' => ['label' => 'Adjudication', 'short' => 'Votes', 'route' => 'admin.adjudication.index', 'icon' => $scales],
            ],
            self::BidAdmin => ['bid-admin' => $fraudReview, 'impact' => $impact, 'participation' => $participation, 'merchants' => $merchants, 'eval' => $eval],
            self::Employee => ['wallet' => ['label' => 'My wallet', 'short' => 'Wallet', 'route' => 'employee.wallet', 'icon' => $wallet]],
            self::Merchant => ['dashboard' => $dashboard, 'menu' => ['label' => 'Menu', 'short' => 'Menu', 'route' => 'merchant.menu', 'icon' => $store]],
            self::EmployerAdmin, self::Judge => ['dashboard' => $dashboard],
        };
    }
}
