<?php

namespace App\Services\Districts;

use App\Enums\Role;
use App\Models\Bid;
use App\Models\District;
use App\Models\Merchant;
use App\Models\MerchantApplication as Application;
use App\Models\MerchantApplicationEvent as Event;
use App\Models\User;
use App\Services\Eligibility\MerchantEligibilityMatcher;
use App\Services\Geo\AddressLookup;
use App\Services\Geo\GeocodeResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * The merchant joining pipeline (ADR 0031), the only place an
 * application's status changes:
 *
 *   applied → eligibility_checked → bid_approved → stripe_onboarding → live
 *                                 ↘ declined (also from applied)
 *
 * Each move is one UPDATE that only succeeds from the expected status, plus
 * one append-only event (who, when, why), in one transaction, so no step is
 * skipped and two presses can't both win.
 *
 * The eligibility check asks Merchant Eligibility Matching for a suggestion
 * (name, address and category only, with no business activity) and checks
 * the boundary. The AI never moves an application beyond
 * eligibility_checked: a BID admin approves or declines.
 */
class MerchantApplications
{
    public const MOVED_ON = 'This application has moved on since you opened it, so nothing changed. Reload the page.';

    public const CHECK_FIRST = 'Run the eligibility check before approving: no step is skipped.';

    public const NEEDS_INSIDE = 'Only an address inside the district can be approved. Run the eligibility check again or decline.';

    public const ADDRESS_SERVICE_DOWN = 'DC\'s address service didn\'t answer, so nothing changed and the AI was not asked. Try again in a minute.';

    public const EMAIL_TAKEN = 'That contact email already has a Local Goods login. Ask the BID to sort it out; nothing changed.';

    public const STATUS_LINK_PATH = '/apply/status';

    public function __construct(private AddressLookup $lookup, private MerchantEligibilityMatcher $matcher) {}

    /**
     * A new application from the public form. Returns it and the private
     * status link token (shown once; only its hash is kept).
     *
     * @param  array{business_name: string, address: string, category: string, contact_email: string, independence_answer: string}  $data
     * @return array{0: Application, 1: string}
     */
    public function submit(array $data, bool $isSample = false): array
    {
        $token = Str::random(40);

        $application = DB::transaction(function () use ($data, $token, $isSample) {
            $application = Application::create([
                'business_name' => trim($data['business_name']), 'address' => trim($data['address']),
                'category' => $data['category'], 'contact_email' => mb_strtolower(trim($data['contact_email'])),
                'independence_answer' => trim($data['independence_answer']),
                'status' => Application::APPLIED, 'status_token_hash' => self::hashToken($token),
                'prototype_acknowledged_at' => now(), 'district_id' => District::default()->id, 'is_sample' => $isSample,
            ]);
            $this->log($application, null, Application::APPLIED, $isSample ? Event::ACTOR_SEED : Event::ACTOR_APPLICANT, null, 'Application sent.');

            return $application;
        });

        return [$application, $token];
    }

    /** The private link: the token is after the #, so it never reaches a server log. */
    public static function statusLink(Application $application, string $token): string
    {
        return url(self::STATUS_LINK_PATH).'#'.$application->id.'.'.$token;
    }

    /** The application a "<id>.<token>" key opens, or null. Same answer for every kind of miss. */
    public static function forKey(string $key): ?Application
    {
        if (! preg_match('/^(\d{1,12})\.([A-Za-z0-9]{40})$/', trim($key), $m)) {
            return null;
        }

        $application = Application::find((int) $m[1]);

        return $application && hash_equals($application->status_token_hash, self::hashToken($m[2])) ? $application : null;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Step 2: where the address is, whether that is inside the district, and
     * the AI's suggestion. From applied (or again from eligibility_checked,
     * e.g. after the address service was down). The status never goes
     * further than eligibility_checked, whatever the AI says.
     */
    public function runCheck(Application $application, User $by): Application
    {
        $this->ensureStaff($by);
        if (! in_array($application->status, [Application::APPLIED, Application::ELIGIBILITY_CHECKED], true)) {
            throw new InvalidArgumentException(self::MOVED_ON);
        }

        $place = $this->lookup->lookup($application->address, AddressLookup::ORIGIN_APPLICATION);
        if ($place->status === GeocodeResult::UNAVAILABLE) {
            throw new InvalidArgumentException(self::ADDRESS_SERVICE_DOWN);
        }

        $district = $application->district;
        $inside = $place->isFound() && DistrictRules::isInside($district, $place->latitude, $place->longitude);
        $suggestion = $this->matcher->assess([
            'name' => $application->business_name, 'address' => $application->address,
            'category' => $application->category, 'business_activity' => '',
        ]);

        return DB::transaction(function () use ($application, $by, $place, $inside, $suggestion) {
            $from = $application->status;
            $moved = Application::whereKey($application->id)->where('status', $from)->update([
                'status' => Application::ELIGIBILITY_CHECKED,
                'matched_address' => $place->matchedAddress, 'latitude' => $place->latitude, 'longitude' => $place->longitude,
                'inside_district' => $place->isFound() ? $inside : null,
                'ai_eligible' => $suggestion->eligible, 'ai_classification' => $suggestion->classification,
                'ai_confidence' => $suggestion->confidence, 'ai_reasoning' => $suggestion->reasoning,
                'ai_needs_review' => $suggestion->needsHumanReview, 'ai_prompt_version' => MerchantEligibilityMatcher::promptVersion(),
                'ai_model' => $suggestion->assessedBy, 'updated_at' => now(),
            ]);
            if ($moved !== 1) {
                throw new InvalidArgumentException(self::MOVED_ON);
            }

            $where = match (true) {
                ! $place->isFound() => 'Address not found in DC\'s Master Address Repository',
                $inside => 'Address inside the district',
                default => 'Address outside the district',
            };
            $this->log($application, $from, Application::ELIGIBILITY_CHECKED, Event::ACTOR_STAFF, $by,
                $where.'. AI suggestion: '.str_replace('_', ' ', $suggestion->classification).' (a suggestion only; a BID admin decides).');

            return $application->fresh();
        });
    }

    /** Step 3: a BID admin approves. Only after the check, and only inside the district. */
    public function approve(Application $application, User $by, string $reason): Application
    {
        $this->ensureStaff($by);
        $reason = $this->reason($reason);
        if ($application->status === Application::APPLIED) {
            throw new InvalidArgumentException(self::CHECK_FIRST);
        }
        if ($application->status !== Application::ELIGIBILITY_CHECKED) {
            throw new InvalidArgumentException(self::MOVED_ON);
        }
        if ($application->inside_district !== true) {
            throw new InvalidArgumentException(self::NEEDS_INSIDE);
        }
        // The boundary rule itself, on the stored point, not only the stored answer.
        DistrictRules::ensureInside($application->district, $application->latitude, $application->longitude);

        return $this->move($application, Application::ELIGIBILITY_CHECKED, Application::BID_APPROVED, Event::ACTOR_STAFF, $by, $reason);
    }

    /** A BID admin declines, before or after the check. Final; the applicant sees the reason. */
    public function decline(Application $application, User $by, string $reason): Application
    {
        $this->ensureStaff($by);
        $reason = $this->reason($reason);
        $from = in_array($application->status, [Application::APPLIED, Application::ELIGIBILITY_CHECKED], true) ? $application->status : '-';

        return $this->move($application, $from, Application::DECLINED, Event::ACTOR_STAFF, $by, $reason, ['decline_reason' => $reason]);
    }

    /**
     * Step 4, by the applicant from their status page: a merchant login and
     * the merchant itself, approved for the district through DistrictRules
     * (so it can only be inside) but not live until Stripe onboarding is
     * done. Returns the login to sign in as.
     */
    public function startOnboarding(Application $application): User
    {
        if ($application->status === Application::STRIPE_ONBOARDING && $application->user !== null) {
            return $application->user;   // pressed again: carry on
        }
        if ($application->status !== Application::BID_APPROVED) {
            throw new InvalidArgumentException(self::MOVED_ON);
        }
        if (User::where('email', $application->contact_email)->exists()) {
            throw new InvalidArgumentException(self::EMAIL_TAKEN);
        }

        return DB::transaction(function () use ($application) {
            $this->claim($application, Application::BID_APPROVED, Application::STRIPE_ONBOARDING);

            $user = User::forceCreate([
                'name' => $application->business_name, 'email' => $application->contact_email,
                'password' => Str::password(32), 'role' => Role::Merchant,
            ]);
            $merchant = Merchant::create([
                'bid_id' => Bid::orderBy('id')->firstOrFail()->id, 'name' => $application->business_name,
                'category' => $application->category, 'address' => $application->address, 'user_id' => $user->id,
                'latitude' => $application->latitude, 'longitude' => $application->longitude,
            ]);
            $approver = $application->events()->where('to_status', Application::BID_APPROVED)->latest('id')->first()?->actorUser;
            DistrictRules::approveMerchant($merchant, $application->district, $approver ?? $user);

            Application::whereKey($application->id)->update(['user_id' => $user->id, 'merchant_id' => $merchant->id]);
            $this->log($application, Application::BID_APPROVED, Application::STRIPE_ONBOARDING, Event::ACTOR_APPLICANT, null,
                'Signed in and started setting up Stripe payouts.');

            return $user;
        });
    }

    /** Step 5: once Stripe says the merchant can receive transfers, it goes live. Safe to call any time. */
    public function goLiveIfReady(Merchant $merchant): bool
    {
        $application = Application::where('merchant_id', $merchant->id)->where('status', Application::STRIPE_ONBOARDING)->first();
        if ($application === null || ! $merchant->isStripeOnboarded()) {
            return false;
        }

        return DB::transaction(function () use ($application, $merchant) {
            if (Application::whereKey($application->id)->where('status', Application::STRIPE_ONBOARDING)
                ->update(['status' => Application::LIVE, 'updated_at' => now()]) !== 1) {
                return false;
            }
            $merchant->forceFill(['live_at' => now()])->save();
            $this->log($application, Application::STRIPE_ONBOARDING, Application::LIVE, Event::ACTOR_STRIPE, null,
                'Stripe confirmed the account can receive transfers. Employees can now spend credits here.');

            return true;
        });
    }

    /** @param  array<string, mixed>  $columns */
    private function move(Application $application, string $from, string $to, string $actor, ?User $by, string $reason, array $columns = []): Application
    {
        return DB::transaction(function () use ($application, $from, $to, $actor, $by, $reason, $columns) {
            $this->claim($application, $from, $to, $columns);
            $this->log($application, $from, $to, $actor, $by, $reason);

            return $application->fresh();
        });
    }

    /** @param  array<string, mixed>  $columns */
    private function claim(Application $application, string $from, string $to, array $columns = []): void
    {
        $moved = Application::whereKey($application->id)->where('status', $from)->update(['status' => $to, 'updated_at' => now(), ...$columns]);
        if ($moved !== 1) {
            throw new InvalidArgumentException(self::MOVED_ON);
        }
    }

    private function log(Application $application, ?string $from, string $to, string $actor, ?User $by, string $reason): void
    {
        Event::create([
            'merchant_application_id' => $application->id, 'from_status' => $from, 'to_status' => $to,
            'actor' => $actor, 'actor_user_id' => $by?->id, 'reason' => $reason,
        ]);
    }

    private function ensureStaff(User $by): void
    {
        if (! $by->hasRole(Role::BidAdmin, Role::PlatformAdmin)) {
            throw new LogicException('Only a BID admin or the platform admin decides on applications.');
        }
    }

    private function reason(string $reason): string
    {
        if (mb_strlen($reason = trim($reason)) < 5) {
            throw new InvalidArgumentException('Write a reason (at least 5 characters). The applicant sees it.');
        }

        return $reason;
    }
}
