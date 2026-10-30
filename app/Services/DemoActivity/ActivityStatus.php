<?php

namespace App\Services\DemoActivity;

use App\Models\DemoActivityAction;
use App\Models\DemoActivityDay;
use App\Models\MerchantPayout;
use App\Services\Fraud\FraudVerdict;
use App\Support\StripeMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the demo activity generator has done (ADR 0032), for its page and
 * `demo-activity:status`: whether it is on, today's progress, each recent
 * weekday's totals, recent actions and the hold rate actually seen. Read
 * only.
 */
class ActivityStatus
{
    public const DAYS_SHOWN = 15;

    public const ACTIONS_SHOWN = 25;

    public function __construct(private DayPlanner $planner) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $today = CarbonImmutable::now(ActivityGuard::TIMEZONE)->startOfDay();
        $days = DemoActivityDay::with(['actions.redemption.stripePayout'])->orderByDesc('date')->take(self::DAYS_SHOWN)->get();
        $generated = DemoActivityAction::where('kind', DemoActivityAction::KIND_REDEMPTION)->where('status', DemoActivityAction::STATUS_DONE)
            ->with('redemption')->get()->filter(fn ($a) => $a->redemption !== null);

        return [
            'state' => ActivityGuard::state(),
            'enabled' => (bool) config('demo_activity.enabled'),
            'refusal' => ActivityGuard::refusal(),
            'test_mode' => StripeMode::active(),
            'ends_on' => CarbonImmutable::parse((string) config('demo_activity.ends_on'), ActivityGuard::TIMEZONE),
            'hours' => config('demo_activity.hours'),
            'caps' => config('demo_activity.caps'),
            'today' => $this->today($today, $days->first(fn ($d) => $d->date->isSameDay($today))),
            'days' => $days->map(fn (DemoActivityDay $day) => $this->totals($day)),
            'actions' => DemoActivityAction::with(['employee', 'merchant', 'redemption', 'purchase', 'correction'])->latest('id')->take(self::ACTIONS_SHOWN)->get(),
            'holds' => [
                'redemptions' => $generated->count(),
                'held' => $generated->filter(fn ($a) => $a->redemption->ai_decision === FraudVerdict::HOLD)->count(),
                'unusual' => $generated->where('unusual', true)->count(),
                'unusual_held' => $generated->where('unusual', true)->filter(fn ($a) => $a->redemption->ai_decision === FraudVerdict::HOLD)->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function today(CarbonImmutable $date, ?DemoActivityDay $day): array
    {
        $plan = $day?->plan ?? $this->planner->plan($date);
        $actions = $day?->actions ?? collect();

        return [
            'date' => $date,
            'planned' => count($plan),
            'next' => collect($plan)->pluck('slot')->diff($actions->pluck('slot'))->isEmpty() ? null
                : collect($plan)->first(fn ($s) => ! $actions->contains('slot', $s['slot']))['at'] ?? null,
            'done' => $actions->where('status', DemoActivityAction::STATUS_DONE)->count(),
            'skipped' => $actions->where('status', DemoActivityAction::STATUS_SKIPPED)->count(),
            'failed' => $actions->where('status', DemoActivityAction::STATUS_FAILED)->count(),
            'halted' => $day?->halted_reason,
            'reconciliation' => $day?->last_reconciliation,
        ];
    }

    /** @return array<string, mixed> */
    private function totals(DemoActivityDay $day): array
    {
        /** @var Collection<int, DemoActivityAction> $actions */
        $actions = $day->actions;
        $done = $actions->where('status', DemoActivityAction::STATUS_DONE);
        $redemptions = $done->where('kind', DemoActivityAction::KIND_REDEMPTION)->filter(fn ($a) => $a->redemption !== null);

        return [
            'date' => $day->date,
            'actions' => $done->count(),
            'redemptions' => $redemptions->count(),
            'in_person' => $redemptions->where('channel', 'in_person')->count(),
            'spend_cents' => (int) $redemptions->sum('amount_cents'),
            'purchase_cents' => (int) $done->where('kind', DemoActivityAction::KIND_PURCHASE)->sum('amount_cents'),
            'held' => $redemptions->filter(fn ($a) => $a->redemption->ai_decision === FraudVerdict::HOLD)->count(),
            'transfers' => $redemptions->filter(fn ($a) => $a->redemption->stripePayout?->status === MerchantPayout::STATUS_SENT)->count(),
            'refund_requests' => $done->where('kind', DemoActivityAction::KIND_REFUND_REQUEST)->count(),
            'skipped' => $actions->where('status', DemoActivityAction::STATUS_SKIPPED)->count(),
            'failed' => $actions->where('status', DemoActivityAction::STATUS_FAILED)->count(),
            'halted' => $day->halted_reason,
            'reconciliation' => $day->last_reconciliation === null ? null : (bool) ($day->last_reconciliation['matches'] ?? false),
        ];
    }
}
