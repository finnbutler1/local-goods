# Merchant candidates: build report

Built 2026-10-14 by `php artisan eligibility:build-candidates`.
Boundary: DC GIS Business Improvement Districts layer, "Golden Triangle BID" (`data/sources/golden_triangle_bid.geojson`).
Licenses: DC Open Data, Basic Business License Points, active licenses returned by a spatial query against that boundary.

A license is kept only if DC's spatial query, the license's own BID field and our point-in-polygon check all put it inside the boundary.

| Step | Licenses |
|---|---|
| Licenses returned | 1874 |
| Not active | 0 |
| Bid field disagrees | 0 |
| Outside our polygon check | 0 |
| Not consumer facing | 1618 |
| Only a personal name | 16 |
| Personal name removed by hand | 5 |
| Duplicate locations merged | 16 |
| **Candidates kept** | **219** |

By category: cafe 28, fitness 2, restaurant 154, retail 15, salon 20.
