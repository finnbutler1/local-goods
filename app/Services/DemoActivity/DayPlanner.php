<?php

namespace App\Services\DemoActivity;

use App\Models\DemoActivityAction;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Draws one weekday's plan for the demo activity generator (ADR 0032).
 * Pure: the same date always gives the same plan, from a generator seeded
 * by the date, so a plan can be shown in advance (--dry-run) and a second
 * run on the same day finds the same slots. Weekends have no plan.
 *
 * A slot says when (DC time), where, how to pay and what to buy. Who buys
 * is picked when the slot runs, from whoever can afford it then. Monthly
 * allowances and buying credits aren't planned: each tick does them when
 * they are needed (ActivityGenerator).
 */
final class DayPlanner
{
    public static function seedFor(CarbonImmutable $date): int
    {
        return crc32('local-goods-demo-activity:'.$date->toDateString()) & 0x7fffffff;
    }

    /**
     * @return list<array{slot: string, kind: string, at: string, merchant?: string, channel?: string, items?: array<string, int>|null, amount_cents?: int|null, unusual?: bool}>
     */
    public function plan(CarbonImmutable $date): array
    {
        $weekday = $date->dayOfWeekIso;
        if ($weekday > 5) {
            return [];
        }

        $random = new Randomizer(new Mt19937(self::seedFor($date)));
        $slots = [];

        [$fewest, $most] = config('demo_activity.redemptions_per_day')[$weekday];
        $count = $random->getInt($fewest, $most);

        for ($i = 1; $i <= $count; $i++) {
            $slots[] = $this->redemption($random, sprintf('r%02d', $i));
        }

        if ($random->nextFloat() < (float) config('demo_activity.unusual_day_share')) {
            $slots[] = $this->unusual($random);
        }

        // One weekday per ISO week for each weekly extra, drawn from the week.
        $week = new Randomizer(new Mt19937(crc32('local-goods-demo-activity-week:'.$date->format('o-W')) & 0x7fffffff));
        $recognitionDay = $week->getInt(1, 5);
        $refundDay = $week->getInt(2, 5);

        if (config('demo_activity.weekly.recognition') && $weekday === $recognitionDay) {
            $slots[] = ['slot' => 'recognition', 'kind' => DemoActivityAction::KIND_RECOGNITION, 'at' => $this->time($random, '09:00', '16:00')];
        }
        if (config('demo_activity.weekly.refund_request') && $weekday === $refundDay) {
            $slots[] = ['slot' => 'refund', 'kind' => DemoActivityAction::KIND_REFUND_REQUEST, 'at' => $this->time($random, '15:00', '17:30')];
        }

        usort($slots, fn ($a, $b) => [$a['at'], $a['slot']] <=> [$b['at'], $b['slot']]);

        return $slots;
    }

    private function redemption(Randomizer $random, string $slot): array
    {
        $periods = config('demo_activity.periods');
        $period = $this->weighted($random, array_map(fn ($p) => $p['weight'], $periods));
        $p = $periods[$period];

        // Lunch peaks in its middle (the mean of two uniform draws); the rest are flat.
        $at = $period === 'lunch'
            ? $this->minutesToTime(intdiv($this->toMinutes($this->time($random, $p['from'], $p['until'])) + $this->toMinutes($this->time($random, $p['from'], $p['until'])), 2))
            : $this->time($random, $p['from'], $p['until']);

        $merchant = $p['merchants'][$random->getInt(0, count($p['merchants']) - 1)];
        $itemised = $random->nextFloat() < (float) config('demo_activity.itemised_share');

        return [
            'slot' => $slot,
            'kind' => DemoActivityAction::KIND_REDEMPTION,
            'at' => $at,
            'merchant' => $merchant,
            'channel' => $random->nextFloat() < (float) config('demo_activity.in_person_share') ? 'in_person' : 'to_go',
            'items' => $itemised ? $this->basket($random, $merchant) : null,
            'amount_cents' => $itemised ? null : $this->typedAmount($random, $merchant),
            'unusual' => false,
        ];
    }

    /** A round amount far above the café's usual, at an hour people rarely spend that much. */
    private function unusual(Randomizer $random): array
    {
        $amounts = config('demo_activity.unusual_cents');

        return [
            'slot' => 'unusual',
            'kind' => DemoActivityAction::KIND_REDEMPTION,
            'at' => $random->getInt(0, 1) === 0 ? $this->time($random, '07:05', '08:30') : $this->time($random, '17:00', '18:30'),
            'merchant' => config('demo_activity.unusual_merchant'),
            'channel' => $random->getInt(0, 1) === 0 ? 'in_person' : 'to_go',
            'items' => null,
            'amount_cents' => $amounts[$random->getInt(0, count($amounts) - 1)],
            'unusual' => true,
        ];
    }

    /** @return array<string, int> item name => quantity, from the demo menu */
    private function basket(Randomizer $random, string $merchant): array
    {
        $menu = array_column(DatabaseSeeder::DEMO_MENUS[$merchant], 0);
        $wanted = in_array($merchant, ['cafe', 'kitchen', 'noodle', 'deli'], true) ? $random->getInt(1, 3) : 1;
        $picked = [];

        foreach ($random->pickArrayKeys($menu, min($wanted, count($menu))) as $index) {
            $picked[$menu[$index]] = $random->nextFloat() < 0.2 ? 2 : 1;
        }

        return $picked;
    }

    /** A typed-in total inside the category's usual range, never a round amount. */
    private function typedAmount(Randomizer $random, string $merchant): int
    {
        [$low, $high] = config('fraud.category_ticket_ranges')[DatabaseSeeder::DEMO_MERCHANTS[$merchant][1]];
        $dollars = $random->getInt(intdiv($low, 100), intdiv($high, 100) - 1);

        return $dollars * 100 + 5 * $random->getInt(1, 19);
    }

    /** @param array<string, int> $weights */
    private function weighted(Randomizer $random, array $weights): string
    {
        $roll = $random->getInt(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            if (($roll -= $weight) <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    private function time(Randomizer $random, string $from, string $until): string
    {
        return $this->minutesToTime($random->getInt($this->toMinutes($from), $this->toMinutes($until) - 1));
    }

    private function toMinutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    private function minutesToTime(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
