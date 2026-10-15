<?php

// Inputs for Merchant Eligibility Matching. Real businesses here are only
// candidates: none of them is a Local Goods participant, and none appears in
// the synthetic demo data.
return [

    // The Golden Triangle BID boundary, from DC GIS (Business Improvement
    // Districts layer). Downloaded by eligibility:build-candidates.
    'boundary_url' => 'https://maps2.dcgis.dc.gov/dcgis/rest/services/DCGIS_DATA/Business_Betterment_Areas_WebMercator/MapServer/15/query',
    'boundary_name' => 'Golden Triangle BID',
    'boundary_path' => base_path('data/sources/golden_triangle_bid.geojson'),

    // DC Open Data, Basic Business License Points (DLCP).
    'licenses_url' => 'https://maps2.dcgis.dc.gov/dcgis/rest/services/FEEDS/DCRA/FeatureServer/13/query',

    // Real businesses inside the boundary, and where each one's point is.
    'candidates_path' => base_path('data/merchant_candidates.csv'),
    'points_path' => base_path('data/sources/merchant_candidate_points.csv'),
    'report_path' => base_path('data/sources/merchant_candidates_report.md'),

    // Each candidate's license "business activity" (e.g. "Restaurant",
    // "Barber Shop"), one of the public facts Merchant Eligibility Matching
    // sees. Written by eligibility:build-candidates and
    // eligibility:fetch-activity.
    'activity_path' => base_path('data/sources/merchant_candidate_activity.csv'),

    // The hand-labeled ground truth for the eval.
    'labels_path' => base_path('data/merchant_labels.csv'),
    'labels_lock_path' => base_path('data/merchant_labels_lock.json'),

    // A license can name the wrong kind of business (Barry's holds a food
    // license for its smoothie bar). Keyed by the name lowercased with only
    // letters and digits kept. Each override is a fact about the business
    // type, never about ownership.
    'category_overrides' => [
        'barrys' => 'fitness',
    ],

    // merchant:eval results (docs/evals, linked from /eval/merchants) and the
    // AI-only answers the BID review queue reads. Committed, so production
    // never calls the API to show them.
    'eval_dir' => base_path('docs/evals'),
    'eval_latest_path' => base_path('docs/evals/merchant-eval-latest.json'),
    'eval_cache_path' => storage_path('app/merchant-eval-cache'),
    'assessments_path' => base_path('data/merchant_assessments.json'),

    // The final Merchant Eligibility Matching prompt (first 8 hex of the SHA-256
    // of resources/prompts/merchant-eligibility.md), frozen by Finn on
    // 2026-10-15 after two dev runs. The holdout runs only with this version,
    // and CI fails if the prompt file changes without a new decision.
    'frozen_prompt_version' => '458e8c2d',

    // Policy: the AI only recommends. No candidate is ever approved without a
    // person's decision (MerchantReviewDecision, made by a BID or platform
    // admin through the review queue). Shown on the queue and the eval page.
    'policy' => 'The AI is a triage tool: it screens out obvious chains and franchises, routes anything it can\'t verify to a person, and never approves a merchant on its own.',

    // Merchant Eligibility Matching: an answer below this confidence, or
    // "undetermined", is always flagged for a person, whatever the AI said.
    'review_if_confidence_below' => 0.7,

    // Any page that ever shows a real candidate must carry this label.
    'candidate_label' => 'Candidate — not a Local Goods participant.',
];
