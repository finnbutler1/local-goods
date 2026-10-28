<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\MerchantApplication;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\Districts\MerchantApplications;
use Tests\Fakes\FakeClaudeClient;

/** Merchant applications (ADR 0031) for tests. Use with FakesDcAddresses. */
trait MakesApplications
{
    protected function eligibilityReply(string $classification = 'independent', float $confidence = 0.9): FakeClaudeClient
    {
        $fake = new FakeClaudeClient(json_encode([
            'eligible' => $classification === 'independent', 'classification' => $classification, 'confidence' => $confidence,
            'reasoning' => 'A single cafe run by a local owner, as far as I know.', 'needs_human_review' => $classification === 'undetermined',
        ]));
        $this->app->instance(ClaudeClient::class, $fake);

        return $fake;
    }

    /** @return array<string, string> */
    protected function applicationData(array $overrides = []): array
    {
        return [
            'business_name' => 'Kettle & Crumb', 'address' => '1700 K St NW', 'category' => 'cafe',
            'contact_email' => 'owner@example.com', 'independence_answer' => 'One shop, owned by two neighbors who live in Adams Morgan.',
            ...$overrides,
        ];
    }

    /** @return array{0: MerchantApplication, 1: string} */
    protected function apply(array $overrides = []): array
    {
        return app(MerchantApplications::class)->submit($this->applicationData($overrides));
    }

    protected function staff(Role $role = Role::BidAdmin): User
    {
        return User::where('role', $role)->first() ?? User::factory()->role($role)->create(['name' => 'Sam Taylor']);
    }

    protected function checked(array $overrides = []): MerchantApplication
    {
        [$application] = $this->apply($overrides);

        return app(MerchantApplications::class)->runCheck($application, $this->staff());
    }

    protected function approved(array $overrides = []): MerchantApplication
    {
        return app(MerchantApplications::class)->approve($this->checked($overrides), $this->staff(), 'Independent, inside the district.');
    }
}
