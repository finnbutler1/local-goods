<?php

namespace App\Support;

use App\Models\Correction;
use App\Models\Feedback;
use App\Models\MerchantApplicationEvent;
use App\Models\OperatingSweep;
use App\Models\RedemptionDecision;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The newest things people decided or asked for, for the admin Home pages:
 * one short line each and a link to the page. No names, emails or amounts.
 * Feedback and sweeps are platform-admin pages, so only they list them.
 */
final class RecentActivity
{
    /** @return Collection<int, array{at: Carbon, text: string, url: string}> */
    public static function for(bool $platformAdmin, int $limit = 10): Collection
    {
        $rows = collect();

        RedemptionDecision::latest('decided_at')->take($limit)->get()->each(fn ($d) => $rows->push([
            'at' => $d->decided_at,
            'text' => 'A held payment was '.match ($d->decision) {
                RedemptionDecision::APPROVE => 'approved',
                RedemptionDecision::DENY => 'denied',
                default => 'cancelled',
            }.' by a person.',
            'url' => route('bid-admin.dashboard'),
        ]));

        Correction::latest('updated_at')->take($limit)->get()->each(fn ($c) => $rows->push([
            'at' => $c->updated_at,
            'text' => $c->typeLabel().': '.strtolower($c->statusLabel()).'.',
            'url' => route('bid-admin.corrections'),
        ]));

        MerchantApplicationEvent::latest('created_at')->take($limit)->get()->each(fn ($e) => $rows->push([
            'at' => $e->created_at,
            'text' => 'A merchant application moved to '.str_replace('_', ' ', $e->to_status).'.',
            'url' => route('bid-admin.applications'),
        ]));

        if ($platformAdmin) {
            Feedback::latest()->take($limit)->get()->each(fn ($f) => $rows->push([
                'at' => $f->created_at,
                'text' => 'New feedback from a signed-in person.',
                'url' => route('admin.feedback.index'),
            ]));

            OperatingSweep::latest()->take($limit)->get()->each(fn ($s) => $rows->push([
                'at' => $s->created_at,
                'text' => 'A sweep to Local Goods Operating was '.($s->status === OperatingSweep::STATUS_FAILED ? 'refused' : 'recorded').'.',
                'url' => route('money').'#sweep',
            ]));
        }

        return $rows->filter(fn ($r) => $r['at'] !== null)->sortByDesc('at')->take($limit)->values();
    }
}
