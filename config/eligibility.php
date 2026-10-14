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

    // Any page that ever shows a real candidate must carry this label.
    'candidate_label' => 'Candidate — not a Local Goods participant.',
];
