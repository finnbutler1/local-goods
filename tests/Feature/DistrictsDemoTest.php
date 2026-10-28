<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\MerchantApplication;
use App\Models\Partnership;
use App\Services\Districts\DistrictRules;
use App\Services\DemoResetter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The demo for districts, partnerships and joining (ADR 0031): one demo
 * partnership and two sample applications, all labelled, fictional, with
 * no AI call and no merchant row; Reset demo restores them.
 */
class DistrictsDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seed_adds_two_labelled_fictional_samples_without_an_ai_call(): void
    {
        Http::preventStrayRequests();
        $this->seed(DatabaseSeeder::class);

        $samples = MerchantApplication::orderBy('id')->get();
        $this->assertSame([MerchantApplication::BID_APPROVED, MerchantApplication::ELIGIBILITY_CHECKED], $samples->pluck('status')->all());

        foreach ($samples as $sample) {
            $this->assertTrue($sample->is_sample);
            $this->assertStringEndsWith('@example.com', $sample->contact_email);
            $this->assertStringStartsWith('Fictional sample', $sample->independence_answer);
            $this->assertSame(DatabaseSeeder::SEEDED_SUGGESTION, $sample->ai_model);
            $this->assertNull($sample->merchant_id, 'a sample is never a participant');
            $this->assertTrue(DistrictRules::isInside($sample->district, $sample->latitude, $sample->longitude));
            $this->assertSame('seed', $sample->events()->latest('id')->first()->actor);
        }
        $this->assertTrue(Partnership::sole()->is_demo);
    }

    public function test_the_samples_are_labelled_on_screen(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->signIn(Role::BidAdmin);

        $this->get('/bid-admin/applications')->assertOk()
            ->assertSee(MerchantApplication::SAMPLE_LABEL)
            ->assertSee('Nineteenth Street Tea House')
            ->assertSee('Desk &amp; Dumpling', false);
        $this->get('/bid-admin/applications/'.MerchantApplication::where('business_name', 'Desk & Dumpling')->value('id'))->assertOk()
            ->assertSee(MerchantApplication::SAMPLE_LABEL)
            ->assertSee('Seeded example, not an AI answer');
        $this->get('/bid-admin/partnership')->assertSee(Partnership::DEMO_LABEL);
    }

    public function test_reset_demo_restores_the_samples_and_drops_other_applications(): void
    {
        $this->seed(DatabaseSeeder::class);
        MerchantApplication::where('is_sample', true)->first()->forceFill(['status' => MerchantApplication::DECLINED])->save();

        app(DemoResetter::class)->reset();

        $this->assertSame(2, MerchantApplication::where('is_sample', true)->count());
        $this->assertSame([MerchantApplication::BID_APPROVED, MerchantApplication::ELIGIBILITY_CHECKED], MerchantApplication::orderBy('id')->pluck('status')->all());
        $this->assertSame(1, Partnership::count());
    }
}
