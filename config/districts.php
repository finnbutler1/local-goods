<?php

// Districts, partnerships and merchant joining (ADR 0031).
return [

    // The one district in the MVP. Its boundary is stored in the districts
    // table (copied by the migration from data/sources/golden_triangle_bid.geojson,
    // the same file eligibility:build-candidates uses). Nothing fetches it at
    // runtime.
    'default_slug' => 'golden-triangle',

    'golden_triangle' => [
        'name' => 'Golden Triangle BID',
        'slug' => 'golden-triangle',
        'boundary_path' => base_path('data/sources/golden_triangle_bid.geojson'),
        'source_name' => 'DC GIS, Business Improvement Districts (DC Open Data)',
        'source_url' => 'https://maps2.dcgis.dc.gov/dcgis/rest/services/DCGIS_DATA/Business_Betterment_Areas_WebMercator/MapServer/15',
        // The dataset's own EDITED date (1771362670000) and the day we downloaded it.
        'source_edited_on' => '2026-02-17',
        'retrieved_on' => '2026-10-14',
        'description' => 'Downtown DC between the White House and Dupont Circle: roughly 16th Street NW on the east, '
            .'about 21st Street NW on the west, Pennsylvania Avenue NW on the south and the blocks just below '
            .'Dupont Circle on the north. The exact line is the polygon DC publishes (216 points).',
    ],

    // DC's Master Address Repository (MAR) address points, published by DC GIS.
    // Keyless. The older keyless MAR verifier (citizenatlas.dc.gov) has been
    // retired, and MAR 2's API needs a key, so we query MAR's own address
    // points layer. Only the address text is sent.
    'mar_address_points_url' => 'https://maps2.dcgis.dc.gov/dcgis/rest/services/DCGIS_DATA/Location_WebMercator/MapServer/0/query',
    'mar_timeout_seconds' => 5,

    // A "not found" answer is kept this long before MAR is asked again.
    'not_found_retry_days' => 7,
];
