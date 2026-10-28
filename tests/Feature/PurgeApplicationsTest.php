<?php

namespace Tests\Feature;

use App\Models\MerchantApplication;
use App\Services\Districts\MerchantApplications;
use App\Services\Geo\AddressLookup;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesDcAddresses;
use Tests\Concerns\MakesApplications;
use Tests\TestCase;

/**
 * applications:purge (ADR 0031): deletes non-sample applications made
 * before a date with their steps, keeps samples, newer ones and any merchant
 * made from them, and purges checker lookups older than 30 days.
 */
class PurgeApplicationsTest extends TestCase
{
    use FakesDcAddresses;
    use MakesApplications;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeDcAddresses();
        $this->eligibilityReply();
    }

    public function test_old_applications_and_their_steps_go_samples_and_newer_ones_stay(): void
    {
        $this->travelTo('2026-11-15 12:00');
        [$old] = $this->apply();
        $live = $this->approved(['contact_email' => 'merchant@example.com']);
        app(MerchantApplications::class)->startOnboarding($live);
        [$sample] = app(MerchantApplications::class)->submit($this->applicationData(['contact_email' => 's@example.com']), isSample: true);
        $this->travelTo('2027-01-05 12:00');
        [$new] = $this->apply(['contact_email' => 'new@example.com']);
        $merchant = $live->fresh()->merchant;

        $this->artisan('applications:purge', ['--before' => '2027-01-01'])
            ->expectsOutputToContain('Deleted 2 application(s) made before 2027-01-01')
            ->assertSuccessful();

        $this->assertFalse(MerchantApplication::whereKey($old->id)->exists());
        $this->assertFalse(MerchantApplication::whereKey($live->id)->exists());
        $this->assertSame(0, DB::table('merchant_application_events')->whereIn('merchant_application_id', [$old->id, $live->id])->count());
        $this->assertTrue(MerchantApplication::whereKey($sample->id)->exists());
        $this->assertTrue(MerchantApplication::whereKey($new->id)->exists());
        $this->assertNotNull($merchant->fresh(), 'the merchant made from it stays');
    }

    public function test_a_dry_run_deletes_nothing_and_a_date_is_required(): void
    {
        $this->apply();

        $this->artisan('applications:purge', ['--before' => '2099-01-01', '--dry-run' => true])->expectsOutputToContain('Would delete 1 application(s)')->assertSuccessful();
        $this->assertSame(1, MerchantApplication::where('is_sample', false)->count());

        $this->artisan('applications:purge')->assertFailed();
        $this->artisan('applications:purge', ['--before' => 'tomorrow'])->assertFailed();
        $this->assertSame(1, MerchantApplication::where('is_sample', false)->count());
    }

    public function test_checker_lookups_older_than_thirty_days_go_application_lookups_stay(): void
    {
        $this->travelTo('2026-11-01 12:00');
        app(AddressLookup::class)->lookup('1700 K St NW', AddressLookup::ORIGIN_CHECKER);
        app(AddressLookup::class)->lookup('1700 K St NW', AddressLookup::ORIGIN_APPLICATION);
        $this->travelTo('2026-11-03 12:00');
        app(AddressLookup::class)->lookup('1100 Connecticut Ave NW', AddressLookup::ORIGIN_CHECKER);
        $this->travelTo('2026-12-02 13:00');   // 31 days after the first, 29 after the second

        $this->artisan('applications:purge', ['--before' => '2000-01-01'])->expectsOutputToContain('and 1 address checker lookup(s)')->assertSuccessful();

        $this->assertSame(['1100 CONNECTICUT AVENUE NW'], DB::table('address_lookups')->where('origin', 'checker')->pluck('matched_address')->all());
        $this->assertSame(1, DB::table('address_lookups')->where('origin', 'application')->count());
    }
}
