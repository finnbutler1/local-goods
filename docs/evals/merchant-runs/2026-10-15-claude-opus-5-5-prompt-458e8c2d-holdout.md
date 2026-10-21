# Merchant eval run: Holdout, prompt `458e8c2d`

> Every business named here is a candidate, not a Local Goods participant. Judged from the AI's own training knowledge and the public license facts shown. It did not search the web, so it may not know about recent sales, closures or new owners.

Run 2026-10-15T18:27:39-04:00 · model `claude-opus-5-5` · prompt [`458e8c2d`](../../../resources/prompts/merchant-eligibility.md) · 20 cases · The one holdout run, with frozen prompt 458e8c2d.

| Measure | Result |
|---|---|
| Eligible yes/no agreement | 17 of 20 (85%, 95% CI 64–94.8%) |
| Baseline: always answer "not eligible" | 17 of 20 (85%, 95% CI 64–94.8%) |
| Of Finn's independent businesses, AI called eligible | 0 of 3 (0%, 95% CI 0–56.1%) |
| Of the AI's eligible calls, Finn labeled independent | n/a (none) |
| Exact classification match | 8 of 20 (40%, 95% CI 21.9–61.3%) |
| Triage: chains and franchises correctly called not eligible | 15 of 17 (88.2%, 95% CI 65.7–96.7%) |
| Triage: chains and franchises wrongly called eligible | 0 of 17 (0%, 95% CI 0–18.4%) |
| Triage: share of all cases routed to human review | 14 of 20 (70%, 95% CI 48.1–85.5%) |
| AI said undetermined on a case Finn decided | 4 of 20 (20%, 95% CI 8.1–41.6%) |
| Finn undetermined: AI also said undetermined | n/a: no case is labeled undetermined |

Confusion matrix (rows: Finn, columns: AI):

| Finn ↓ / AI → | Eligible | Not eligible | Undetermined |
|---|---|---|---|
| Independent | 0 | 1 | 2 |
| Not independent | 0 | 15 | 2 |
| Undetermined | 0 | 0 | 0 |

By the reason Finn gave (not independent cases):

| Reason | Cases | AI said not eligible | AI gave the same reason | AI undetermined | AI called eligible |
|---|---|---|---|---|---|
| Local chain | 3 | 3 of 3 (100%, 95% CI 43.9–100%) | 1 of 3 (33.3%, 95% CI 6.1–79.2%) | 0 | 0 |
| Franchise | 9 | 8 of 9 (88.9%, 95% CI 56.5–98%) | 3 of 9 (33.3%, 95% CI 12.1–64.6%) | 1 | 0 |
| National chain | 5 | 4 of 5 (80%, 95% CI 37.6–96.4%) | 4 of 5 (80%, 95% CI 37.6–96.4%) | 1 | 0 |
| Outside control | 0 | n/a (none) | n/a (none) | 0 | 0 |

Category disagreements (7): In these cases both answers mean "not eligible"; only the reason differs. Finn notes that some of Finn's own fail_reason labels may be inaccurate (for example, company-operated locations labeled franchise). The labels stay locked and unchanged, so these still count as classification disagreements.

MC-116 Mason's Famous Lobster Rolls (Finn: Local chain, AI: Franchise), MC-132 PANERA BREAD (Finn: Franchise, AI: National chain), MC-145 PRET A MANGER (Finn: Franchise, AI: National chain), MC-165 SHAKE SHACK 18TH STREET NW WASHINGTON DC LLC (Finn: Franchise, AI: National chain), MC-186 SWEETGREEN DUPONT, LLC. (Finn: Franchise, AI: National chain), MC-190 Tatte Bakery & Cafe (Finn: Local chain, AI: National chain), MC-208 TRAVEL TRADERS #209 (Finn: Franchise, AI: National chain).

Disagreements (12): every case where the AI's classification differs from Finn's, including the category disagreements above. **Bold** means the eligible yes/no answer differs too.

- MC-016 Barry's (fitness, Delicatessen). Candidate, not a participant.
  - Finn: Not independent (National chain). only 2 DC stores but 100 national locations [Source](https://athletechnews.com/barrys-opens-100th-studio-wall-street-new-york-city/)
  - AI: Undetermined, confidence 60%. The name suggests Barry's (formerly Barry's Bootcamp), a national fitness chain headquartered outside the DC area, which would fail part 1 of the rule. However, the license lists the business activity as "Delicatessen" rather than a fitness activity, so I cannot be sure this license belongs to that chain and not to an unrelated deli or a juice bar inside the studio. I am relying on the name alone, so ownership and location count cannot be confirmed.

- **MC-020 BOMBAY CLUB INC** (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Independent. only one location right outside of the White House [Source](https://www.bombayclubdc.com/history)
  - AI: Local chain, confidence 75%. From my training, Bombay Club at 815 Connecticut Ave NW is owned by restaurateur [name removed]'s Knightsbridge Restaurant Group, which is based in DC with no known outside parent or investor control. Its sister restaurants have included two Rasika locations, Bindaas locations, Annabelle, Sababa and Modena, which together appear to exceed five locations. That fails part 2 of the rule. My knowledge may be out of date, since restaurants in this group have opened and closed over time, so the current location count should be checked. _Redacted on 2026-10-21: a real person's name or personal detail was removed from this reasoning. The classification and confidence are the AI's original answer._

- **MC-076 GCDC** (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Independent. one location [Source](https://www.grilledcheesebar.com/)
  - AI: Undetermined, confidence 60%. GCDC appears to be the Grilled Cheese Bar near Pennsylvania Ave NW, which I recall as a single local DC restaurant. However, I am not confident in its current ownership or whether it has sister brands or additional locations, and my knowledge may be out of date. Because the rule requires certainty on both local ownership and a location count of five or fewer, this should be verified by a person.

- MC-116 Mason's Famous Lobster Rolls (restaurant, Delicatessen). Candidate, not a participant.
  - Finn: Not independent (Local chain). prominent DMV locations [Source](https://www.masonslobster.com/)
  - AI: Franchise, confidence 75%. From my training, Mason's Famous Lobster Rolls is a lobster roll brand that started in Annapolis, Maryland and has grown through franchising, with locations in several states. Many of its locations, likely including this one, are run by franchisees, which fails part 3 of the rule. Even if this location were company-run, the brand has well over five locations, so it would still not be eligible. My knowledge may be out of date and I can't confirm who operates this specific location, so a person should verify.

- MC-132 PANERA BREAD (restaurant, Caterers). Candidate, not a participant.
  - Finn: Not independent (Franchise). global chain [Source](https://www.panerabread.com/en-us/cafe/locations/dc/washington)
  - AI: National chain, confidence 80%. Panera Bread is a large national bakery-cafe chain headquartered in the St. Louis area and owned by a private investment group, with thousands of locations. That fails part 1 of the rule, and it also fails the five-location limit. Many Panera locations are run by franchisees, and I can't confirm whether this one is company-owned or franchised. If it is franchised, the correct label would be franchise, but it is ineligible either way.

- MC-145 PRET A MANGER (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Franchise). global chain [Source](https://www.pret.com/en-US/shop-finder)
  - AI: National chain, confidence 95%. Pret A Manger is a large international sandwich and coffee chain headquartered in London, owned by JAB Holding, with many locations in the US and abroad. It fails part 1 of the rule because it is run by an operator headquartered outside the DC metro area. It also has far more than five locations. This is based on my general knowledge, which may be out of date, but the brand's chain status is well established.

- MC-148 QARGO COFFEE (cafe, Delicatessen). Candidate, not a participant.
  - Finn: Not independent (Franchise). global chain [Source](https://qargocoffee.com/stores/washington-lafayette)
  - AI: Undetermined, confidence 60%. I have only a vague recollection of the Qargo Coffee name and cannot reliably confirm who owns it, where it is headquartered, or how many locations it and any sister brands have. Without clear knowledge of ownership and location count, I cannot confirm it meets all three parts of the rule. This judgment rests mostly on the name, and any memory I have may be out of date.

- MC-165 SHAKE SHACK 18TH STREET NW WASHINGTON DC LLC (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Franchise). global chain [Source](https://shakeshack.com/)
  - AI: National chain, confidence 93%. Shake Shack is a widely known burger chain headquartered in New York and publicly traded, with hundreds of locations. Its domestic restaurants are generally company-operated rather than franchised, so it fails part 1 of the rule because it belongs to an operator headquartered outside the DC metro area. The license name follows Shake Shack's usual pattern for location-specific LLCs, though this is based on my training knowledge rather than a check of current records.

- MC-186 SWEETGREEN DUPONT, LLC. (restaurant, Delicatessen). Candidate, not a participant.
  - Finn: Not independent (Franchise). national chain [Source](https://www.sweetgreen.com/)
  - AI: National chain, confidence 88%. Sweetgreen is a salad chain that started in DC but is now a public company (NYSE: SG) headquartered in Los Angeles, with well over a hundred locations nationwide. Because the operator is headquartered outside the DC metro area, it fails part 1 of the rule, which comes before the outside-control and location-count tests in our order. The LLC name suggests this is a company-operated Sweetgreen store, though this is based on my training knowledge, which may be out of date.

- MC-190 Tatte Bakery & Cafe (cafe, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Local chain). operate out of several locations in DC [Source](https://tattebakery.com/locations/)
  - AI: National chain, confidence 88%. From my training knowledge, Tatte Bakery & Cafe is a bakery-cafe chain founded and headquartered in the Boston area, with many locations in Massachusetts and the DC region. I also recall it received a significant outside investment (reportedly from Advent International), but the first failing reason in the rule order is that it is headquartered outside the DC metro area, which fails part 1. It also clearly exceeds five locations. My information may be out of date, but a change that would make it eligible is very unlikely.

- MC-208 TRAVEL TRADERS #209 (retail, Patent Medicine). Candidate, not a participant.
  - Finn: Not independent (Franchise). operates out of hotels [Source](https://www.traveltradersllc.com/)
  - AI: National chain, confidence 75%. From memory, Travel Traders runs gift and convenience shops inside hotels across the country, and this address appears to be the Mayflower Hotel. I recall the company being based in Florida rather than the DC area, which would fail part 1 of the rule. The store number '#209' on the license also suggests far more than five locations, failing part 2 regardless of headquarters. My knowledge of its ownership and headquarters may be out of date, so a person should confirm.

- **MC-209 TSHIFFELY PHARMACY** (retail, Food Products). Candidate, not a participant.
  - Finn: Independent. only one location [Source](https://www.rxdc.com/)
  - AI: Undetermined, confidence 60%. The name appears to be a variant spelling of Tschiffely Pharmacy, which I recall as a long-running, locally owned DC pharmacy business with several downtown locations. I am not confident of its current number of locations or whether ownership has changed, so I can't confirm part 2 (five or fewer locations) or part 1 (no outside control). The spelling difference and the 'Food Products' license activity also make me unsure this license is the same business.
