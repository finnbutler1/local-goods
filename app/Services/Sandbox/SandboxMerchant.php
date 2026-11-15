<?php

namespace App\Services\Sandbox;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Which business the sandbox's merchant login runs (ADR 0045 addendum,
 * "The Merchant role follows where the money went"). There is one merchant
 * login per sandbox; switching merchant moves its link (`merchants.user_id`)
 * from one merchant row to another, in the sandbox's own file only. The
 * merchant pages already show only `$user->merchant`'s sales, so each
 * merchant sees only its own.
 *
 * Nothing here works without an active sandbox: the default connection would
 * be the live one.
 */
class SandboxMerchant
{
    public function __construct(private Sandbox $sandbox, private SandboxFiles $files) {}

    /**
     * The merchants the role bar offers: where the sandbox employee can pay,
     * and not linked to any login other than the sandbox merchant's.
     *
     * @return Collection<int, Merchant>
     */
    public function pickable(): Collection
    {
        $this->ensureActive();
        $employee = $this->employee();
        $user = $this->merchantUser();

        return Merchant::query()
            ->when($employee !== null, fn ($q) => $q->redeemableFor($employee))
            ->where(fn ($q) => $q->whereNull('user_id')->when($user !== null, fn ($q) => $q->orWhere('user_id', $user->id)))
            ->orderBy('name')->orderBy('id')
            ->get();
    }

    /** Where the sandbox employee last paid since this sandbox started (seeded history doesn't count). */
    public function lastPaid(): ?Merchant
    {
        $this->ensureActive();
        $employee = $this->employee();
        $since = $this->files->createdAt((string) $this->sandbox->id());
        if ($employee === null || $since === null) {
            return null;
        }

        $merchantId = Redemption::where('employee_id', $employee->id)
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->value('merchant_id');

        return $merchantId === null ? null : $this->pickable()->firstWhere('id', $merchantId);
    }

    /** What the Merchant button opens: where the money last went, else the demo merchant. */
    public function default(): ?Merchant
    {
        return $this->lastPaid()
            ?? $this->pickable()->firstWhere('name', config('demo.accounts.merchant.business'));
    }

    /** Point the sandbox merchant login at this merchant (and away from any other). */
    public function linkTo(Merchant $merchant, User $user): void
    {
        $this->ensureActive();
        if (! SandboxUserProvider::isSandboxUser($user) || $user->role !== Role::Merchant) {
            throw new LogicException('Only the sandbox merchant login can be linked.');
        }
        if ($merchant->getConnectionName() !== Sandbox::CONNECTION) {
            throw new LogicException('Only a merchant in this sandbox can be linked.');
        }
        if (! $this->pickable()->contains('id', $merchant->id)) {
            throw new LogicException('That merchant cannot be linked in this sandbox.');
        }

        DB::connection(Sandbox::CONNECTION)->transaction(function () use ($merchant, $user) {
            Merchant::where('user_id', $user->id)->whereKeyNot($merchant->id)->update(['user_id' => null]);
            Merchant::whereKey($merchant->id)->update(['user_id' => $user->id]);
        });
        $user->unsetRelation('merchant');
    }

    private function employee(): ?Employee
    {
        $id = User::where('email', SandboxRoutes::email('employee'))->value('id');

        return $id === null ? null : Employee::where('user_id', $id)->first();
    }

    private function merchantUser(): ?User
    {
        $user = User::where('email', SandboxRoutes::email('merchant'))->first();

        return SandboxUserProvider::isSandboxUser($user) ? $user : null;
    }

    private function ensureActive(): void
    {
        if (! Sandbox::active() || DB::getDefaultConnection() !== Sandbox::CONNECTION) {
            throw new LogicException('Merchants can only be switched inside a sandbox.');
        }
    }
}
