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
}
