<?php

namespace Tests\Feature;

use App\Support\EmployerPlatformFee;
use App\Support\ProductionConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The employer platform fee setting (ADR 0042): built and tested, switched
 * off during the pilot. Off unless EMPLOYER_PLATFORM_FEE_ENABLED is exactly
 * true; the price is the projection's base figure; never on together with
 * the percent platform fee (ADR 0021).
 */
class EmployerPlatformFeeTest extends TestCase
{
    private const ENV = 'EMPLOYER_PLATFORM_FEE_ENABLED';

    /** The flag as config/payments.php reads it with this environment value (null = not set at all). */
    private function flagWith(?string $value): bool
    {
        $before = [getenv(self::ENV), $_ENV[self::ENV] ?? null, $_SERVER[self::ENV] ?? null];
        try {
            if ($value === null) {
                putenv(self::ENV);
                unset($_ENV[self::ENV], $_SERVER[self::ENV]);
            } else {
                putenv(self::ENV.'='.$value);
                $_ENV[self::ENV] = $_SERVER[self::ENV] = $value;
            }

            return (require config_path('payments.php'))['employer_platform_fee_enabled'];
        } finally {
            $before[0] === false ? putenv(self::ENV) : putenv(self::ENV.'='.$before[0]);
            foreach (['_ENV' => 1, '_SERVER' => 2] as $global => $i) {
                if ($before[$i] === null) {
                    unset($GLOBALS[$global][self::ENV]);
                } else {
                    $GLOBALS[$global][self::ENV] = $before[$i];
                }
            }
        }
    }

    public function test_it_is_off_when_the_environment_does_not_set_it_including_in_production(): void
    {
        $this->assertFalse($this->flagWith(null), 'No value means off');
        $this->assertFalse(config('payments.employer_platform_fee_enabled'), 'Off in the test environment too');
        $this->assertFalse(EmployerPlatformFee::enabled());

        // The production path: APP_ENV production, the flag unset. The deploy check is content and the fee is off.
        config(['app.env' => 'production', 'payments.employer_platform_fee_enabled' => $this->flagWith(null)]);
        $this->assertFalse(EmployerPlatformFee::charges());
        $this->assertNull(EmployerPlatformFee::problem());
        $this->assertSame([], array_values(array_filter(ProductionConfig::problems(), fn ($p) => str_contains($p, self::ENV))));
    }

    public function test_only_exactly_true_switches_it_on(): void
    {
        foreach (['', 'false', '0', '1', 'yes', 'on', 'TRUE '] as $value) {
            $this->assertFalse($this->flagWith($value), "\"{$value}\" must not switch it on");
        }
        $this->assertTrue($this->flagWith('true'));
    }

    public function test_no_deploy_file_or_seeder_switches_it_on(): void
    {
        $this->assertStringContainsString("env('".self::ENV."', false) === true", File::get(config_path('payments.php')));

        $files = collect([...File::allFiles(base_path('.ebextensions')), ...File::allFiles(base_path('.platform')), ...File::allFiles(base_path('scripts')),
            ...File::allFiles(database_path('seeders'))])
            ->merge(File::glob(base_path('.github/workflows/*')))->merge([base_path('.env.example'), base_path('phpunit.xml')]);
        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression('/'.self::ENV.'\s*[:=]\s*["\']?\w/', File::get((string) $file), (string) $file);
            $this->assertStringNotContainsString('employer_platform_fee_enabled', File::get((string) $file), (string) $file);
        }
    }

    public function test_the_price_is_the_projections_base_figure_in_cents(): void
    {
        $this->assertSame(6, config('projections.assumptions.employer_platform_fee.base'));
        $this->assertSame(600, EmployerPlatformFee::unitCents());

        config(['projections.assumptions.employer_platform_fee.base' => 7.5]);
        $this->assertSame(750, EmployerPlatformFee::unitCents());
    }

    public function test_a_bad_price_or_both_fees_on_is_refused_and_stops_a_deploy(): void
    {
        config(['payments.employer_platform_fee_enabled' => true]);
        $this->assertNull(EmployerPlatformFee::problem());
        $this->assertTrue(EmployerPlatformFee::charges());

        foreach ([0, -1, 50.01, '6', null] as $bad) {
            config(['projections.assumptions.employer_platform_fee.base' => $bad]);
            $this->assertNotNull(EmployerPlatformFee::problem(), var_export($bad, true));
            $this->assertFalse(EmployerPlatformFee::charges(), 'A bad price never charges');
        }
        config(['projections.assumptions.employer_platform_fee.base' => 6]);

        config(['payments.platform_fee_percent' => '2.5']);
        $this->assertStringContainsString('can\'t both be on', (string) EmployerPlatformFee::problem());
        $this->assertFalse(EmployerPlatformFee::charges());
        $this->assertContains(EmployerPlatformFee::problem(), ProductionConfig::problems());

        // Off, nothing about it is ever a problem, whatever else is set.
        config(['payments.employer_platform_fee_enabled' => false]);
        $this->assertNull(EmployerPlatformFee::problem());
    }

    public function test_the_month_is_dc_time(): void
    {
        $this->assertSame('2027-02', EmployerPlatformFee::monthOf(Carbon::parse('2027-03-01 04:30', 'UTC')), '11:30 PM on Feb 28 in DC');
        $this->assertSame('2027-03', EmployerPlatformFee::monthOf(Carbon::parse('2027-03-01 05:00', 'UTC')));
        $this->assertSame('March 2027', EmployerPlatformFee::monthLabel('2027-03'));
    }
}
