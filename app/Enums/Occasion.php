<?php

namespace App\Enums;

/**
 * Why an employer handed credits to an employee. Monthly is the regular
 * allowance; the other three are optional and switched on in the program
 * setup by giving them an amount (stored on the employer).
 */
enum Occasion: string
{
    case Monthly = 'monthly';
    case Welcome = 'welcome';
    case Birthday = 'birthday';
    case Recognition = 'recognition';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly allowance',
            self::Welcome => 'New-hire welcome',
            self::Birthday => 'Birthday',
            self::Recognition => 'Recognition award',
        };
    }

    /** The employers column holding this occasion's amount. */
    public function column(): string
    {
        return match ($this) {
            self::Monthly => 'monthly_allowance_cents',
            self::Welcome => 'welcome_cents',
            self::Birthday => 'birthday_cents',
            self::Recognition => 'recognition_cents',
        };
    }

    /** @return list<self> The optional ones, in the order the setup form shows them. */
    public static function optional(): array
    {
        return [self::Welcome, self::Birthday, self::Recognition];
    }
}
