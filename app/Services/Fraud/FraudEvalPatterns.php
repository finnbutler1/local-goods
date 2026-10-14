<?php

namespace App\Services\Fraud;

/**
 * Pattern tags for eval cases, worked out from the case's facts only (never
 * from the person's label or the AI's answer), so misses and false alarms
 * can be grouped by what the redemption looked like.
 */
class FraudEvalPatterns
{
    public const TAGS = [
        'round_amount' => 'Round amount ($20+, whole $10s)',
        'triple_median' => '3x or more the usual amount',
        'most_of_balance' => 'Uses 90%+ of the balance',
        'new_merchant' => 'New merchant (0 visits in 30 days)',
        'outside_hours' => 'Outside business hours',
        'frequent_visitor' => '5+ visits here in 7 days',
        'above_category' => "Above the category's typical range",
    ];

    /**
     * @param  array<string, string>  $case  a row from the cases file
     * @param  array<string, mixed>  $signals  FraudEvalRunner::signalsFor($case)
     * @return list<string>
     */
    public static function tags(array $case, array $signals): array
    {
        $amount = (float) $case['amount'];
        $median = $case['median_past_amount'] === '' ? null : (float) $case['median_past_amount'];

        return array_keys(array_filter([
            'round_amount' => $signals['is_round_number'],
            'triple_median' => $median !== null && $amount >= 3 * $median,
            'most_of_balance' => $amount >= 0.9 * (float) $case['balance_before'],
            'new_merchant' => (int) $case['visits_30d'] === 0,
            'outside_hours' => $signals['outside_business_hours'],
            'frequent_visitor' => (int) $case['visits_7d'] >= 5,
            'above_category' => $signals['amount_vs_category_range'] === 'above',
        ]));
    }

    /**
     * Misses and false alarms per tag and per merchant category.
     *
     * @param  array<array<string, mixed>>  $results  run results, each with tags
     * @return array{tags: array<string, array<string, int>>, categories: array<string, array<string, int>>}
     */
    public static function breakdown(array $results): array
    {
        $row = fn (array $group) => [
            'cases' => count($group),
            'suspicious' => count(array_filter($group, fn ($r) => $r['my_label'] === 'suspicious')),
            'missed' => count(array_filter($group, fn ($r) => $r['my_label'] === 'suspicious' && $r['ai_label'] === 'normal')),
            'false_alarms' => count(array_filter($group, fn ($r) => $r['my_label'] === 'normal' && $r['ai_label'] === 'suspicious')),
        ];

        $tags = [];
        foreach (array_keys(self::TAGS) as $tag) {
            $tags[$tag] = $row(array_filter($results, fn ($r) => in_array($tag, $r['tags'] ?? [], true)));
        }

        $categories = [];
        foreach (array_unique(array_column($results, 'category')) as $category) {
            $categories[$category] = $row(array_filter($results, fn ($r) => $r['category'] === $category));
        }
        ksort($categories);

        return ['tags' => $tags, 'categories' => $categories];
    }
}
