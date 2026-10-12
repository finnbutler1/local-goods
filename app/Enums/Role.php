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
     * Sidebar and mobile tab bar items for this role.
     *
     * @return array<string, array{label: string, short: string, route: string, icon: string}>
     */
    public function navigation(): array
    {
        $home = 'M3.75 12 12 4.5l8.25 7.5M5.25 10.5v9h4.5v-5.25h4.5v5.25h4.5v-9';
        $wallet = 'M3.75 7.5h15a1.5 1.5 0 0 1 1.5 1.5v9a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 2.25 18V6a1.5 1.5 0 0 1 1.5-1.5h12M16.5 13.5h.01';
        $shield = 'M12 3 4.5 6v5.25c0 4.5 3.2 8.4 7.5 9.75 4.3-1.35 7.5-5.25 7.5-9.75V6L12 3Zm-3 9 2.25 2.25L15 10.5';
        $chart = 'M4.5 19.5h15M7.5 16.5v-4.5M12 16.5V7.5M16.5 16.5v-7.5';
        $people = 'M15 19.5v-1.5a3.75 3.75 0 0 0-3.75-3.75h-4.5A3.75 3.75 0 0 0 3 18v1.5M9 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm12 9v-1.5a3.75 3.75 0 0 0-2.81-3.63M15.75 4.6a3 3 0 0 1 0 5.8';

        $fraudReview = ['label' => 'Fraud review', 'short' => 'Review', 'route' => 'bid-admin.dashboard', 'icon' => $shield];
        $eval = ['label' => 'AI eval', 'short' => 'AI eval', 'route' => 'bid-admin.eval', 'icon' => $chart];
        $dashboard = ['label' => 'Dashboard', 'short' => 'Home', 'route' => $this->dashboardRoute(), 'icon' => $home];

        return match ($this) {
            self::PlatformAdmin => [
                'dashboard' => $dashboard,
                'bid-admin' => $fraudReview,
                'eval' => $eval,
                'reviewers' => ['label' => 'Reviewers', 'short' => 'Reviewers', 'route' => 'admin.reviewers.index', 'icon' => $people],
            ],
            self::BidAdmin => ['bid-admin' => $fraudReview, 'eval' => $eval],
            self::Employee => ['wallet' => ['label' => 'My wallet', 'short' => 'Wallet', 'route' => 'employee.wallet', 'icon' => $wallet]],
            self::EmployerAdmin, self::Merchant, self::Judge => ['dashboard' => $dashboard],
        };
    }
}
