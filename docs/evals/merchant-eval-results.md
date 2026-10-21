# Merchant Eligibility Matching: eval results

Written by `php artisan merchant:eval`. Finn hand-labeled 60 real businesses in the Golden Triangle BID against the independence rule (40 dev, 20 holdout), with a public source for each, and locked the labels (`data/merchant_labels_lock.json`) before the AI saw them. The AI saw only each business's name, address, category and license business activity.

> **Every business named here is a candidate, not a Local Goods participant.** These files name real businesses because the repo holds the source data; the public web page shows only totals.

> **The AI had no web access.** Judged from the AI's own training knowledge and the public license facts shown. It did not search the web, so it may not know about recent sales, closures or new owners.

**Dev and holdout are reported separately and never pooled.** The prompt may be changed using dev cases only; the holdout is scored once per prompt version.

Prompt versions that have seen the holdout: `458e8c2d`.

## Dev

Run 2026-10-15T18:21:53-04:00 · model `claude-opus-5-5` · prompt [`458e8c2d`](../../resources/prompts/merchant-eligibility.md) · 40 cases · Prompt change 1 (dev only): independent only when sure of local ownership and 5 or fewer locations.

| Measure | Result |
|---|---|
| Eligible yes/no agreement | 28 of 40 (70%, 95% CI 54.6–81.9%) |
| Baseline: always answer "not eligible" | 29 of 40 (72.5%, 95% CI 57.2–83.9%) |
| Of Finn's independent businesses, AI called eligible | 2 of 11 (18.2%, 95% CI 5.1–47.7%) |
| Of the AI's eligible calls, Finn labeled independent | 2 of 5 (40%, 95% CI 11.8–76.9%) |
| Exact classification match | 11 of 40 (27.5%, 95% CI 16.1–42.8%) |
| Triage: chains and franchises correctly called not eligible | 22 of 29 (75.9%, 95% CI 57.9–87.8%) |
| Triage: chains and franchises wrongly called eligible | 3 of 29 (10.3%, 95% CI 3.6–26.4%) |
| Triage: share of all cases routed to human review | 32 of 40 (80%, 95% CI 65.2–89.5%) |
| AI said undetermined on a case Finn decided | 13 of 40 (32.5%, 95% CI 20.1–48%) |
| Finn undetermined: AI also said undetermined | n/a: no case is labeled undetermined |

Confusion matrix (rows: Finn, columns: AI):

| Finn ↓ / AI → | Eligible | Not eligible | Undetermined |
|---|---|---|---|
| Independent | 2 | 0 | 9 |
| Not independent | 3 | 22 | 4 |
| Undetermined | 0 | 0 | 0 |

By the reason Finn gave (not independent cases):

| Reason | Cases | AI said not eligible | AI gave the same reason | AI undetermined | AI called eligible |
|---|---|---|---|---|---|
| Local chain | 10 | 5 of 10 (50%, 95% CI 23.7–76.3%) | 2 of 10 (20%, 95% CI 5.7–51%) | 2 | 3 |
| Franchise | 12 | 12 of 12 (100%, 95% CI 75.8–100%) | 3 of 12 (25%, 95% CI 8.9–53.2%) | 0 | 0 |
| National chain | 7 | 5 of 7 (71.4%, 95% CI 35.9–91.8%) | 4 of 7 (57.1%, 95% CI 25–84.2%) | 2 | 0 |
| Outside control | 0 | n/a (none) | n/a (none) | 0 | 0 |

Category disagreements (13): In these cases both answers mean "not eligible"; only the reason differs. Finn notes that some of Finn's own fail_reason labels may be inaccurate (for example, company-operated locations labeled franchise). The labels stay locked and unchanged, so these still count as classification disagreements.

MC-032 CAVA MEZZE GRILL, LLC. (Finn: National chain, AI: Outside control), MC-036 Chipotle Mexican Grill (Finn: Franchise, AI: National chain), MC-096 JOE & THE JUICE NEW YORK LLC (Finn: Franchise, AI: National chain), MC-101 KRISPY KREME DOUGHNUT (Finn: Franchise, AI: National chain), MC-107 Le Pain Quotidien (Finn: Local chain, AI: National chain), MC-110 LUKE'S LOBSTER (Finn: Franchise, AI: National chain), MC-120 MORTON'S OF CHICAGO/WASHINGTON SQUARE INC (Finn: Franchise, AI: National chain), MC-124 NANDO'S PERI PERI (Finn: Franchise, AI: National chain), MC-134 PEET'S COFFEE & TEA (Finn: Local chain, AI: National chain), MC-142 POTBELLY SANDWICH SHOP (Finn: Local chain, AI: National chain), MC-158 Roti Restaurant Group LLC (Finn: Franchise, AI: National chain), MC-178 STARBUCKS CORPORATION (Finn: Franchise, AI: National chain), MC-217 WAWA, INC. (Finn: Franchise, AI: National chain).

Disagreements (29): every case where the AI's classification differs from Finn's, including the category disagreements above. **Bold** means the eligible yes/no answer differs too.

- **MC-024 Bully** (restaurant, General Business). Candidate, not a participant.
  - Finn: Independent. only one location [Source](https://www.bullywdc.com/)
  - AI: Undetermined, confidence 80%. I do not recognize a business called "Bully" at 2033 M St NW, so I cannot say who owns it or how many locations it has. The name alone gives no reliable information about ownership, franchise status or sister brands. Because ownership and location count can't be established, it is labeled undetermined and should be checked by a person.

- MC-032 CAVA MEZZE GRILL, LLC. (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (National chain). operates in every state [Source](https://cava.com/locations#DC)
  - AI: Outside control, confidence 85%. CAVA is a fast-casual Mediterranean chain founded in the DC area and, to my knowledge, headquartered in Washington, DC. It became a publicly traded company in 2023 and operates hundreds of locations nationwide. Public-company control fails part 1 of the rule, so it is not eligible. This is based on my training knowledge, which may be out of date, so a quick check of the headquarters is advised.

- MC-036 Chipotle Mexican Grill (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Franchise). many locations [Source](https://locations.chipotle.com/dc)
  - AI: National chain, confidence 95%. Chipotle Mexican Grill is a publicly traded national restaurant chain with thousands of company-operated locations, headquartered outside the DC metro area (Newport Beach, California). Chipotle generally does not franchise, so it fails part 1 of the rule as a chain headquartered outside the DC area. This is based on my own knowledge rather than the license data, but the ineligibility is not in doubt.

- MC-045 Colada Shop Dupont LLC. (restaurant, General Business). Candidate, not a participant.
  - Finn: Not independent (Local chain). 7 locations in DC [Source](https://www.coladashop.com/locations)
  - AI: Undetermined, confidence 60%. Colada Shop is a Cuban cafe and bar brand that, from my training knowledge, was founded and is run by a DC-area team, so local ownership looks likely. However, it has several locations in DC and Northern Virginia (for example 14th Street, the Wharf and Sterling, plus this Dupont site), and I cannot confirm whether the total, including any sister concepts, is five or fewer. My knowledge may also be out of date on new openings, closures or outside investment. Part 2 of the rule (location count) can't be confirmed, so a person should verify the current location count and ownership.

- **MC-071 FILTER COFFEEHOUSE** (restaurant, Delicatessen). Candidate, not a participant.
  - Finn: Independent. one location [Source](https://www.filtercoffeehouse.com/)
  - AI: Undetermined, confidence 60%. I recognize Filter Coffeehouse as a small, DC-based coffee shop brand that has had a handful of locations around the city, including Dupont Circle and Foggy Bottom. I believe it is locally owned, but I am not certain of its current total location count or whether it has added locations or sister brands since my information was last updated. Because the rule requires confidence that the business and any sister brands have five or fewer locations, I cannot confirm eligibility from memory alone.

- **MC-084 HAY ADAMS HOTEL** (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Independent. one location [Source](https://www.hayadams.com/location/)
  - AI: Undetermined, confidence 60%. The Hay-Adams is a well-known luxury hotel at 16th and H Streets NW. From memory, it has been owned by a DC-area real estate investment group, but I am not certain of the current owner, whether any outside investors or a management company control it, or how many properties sit under common ownership. Because ownership and location count can't be confirmed for parts 1 and 2 of the rule, it is labeled undetermined. My knowledge may be out of date.

- MC-086 Henry's Salon (salon, Barber Shop). Candidate, not a participant.
  - Finn: Not independent (Local chain). three stores [Source](https://henrybarber.shop/)
  - AI: Undetermined, confidence 80%. I do not clearly recognize a business called Henry's Salon at 1924 I St NW, so I can't confirm who owns it, whether it has other locations, or whether it is a franchise. The name sounds local, but that alone is not evidence that it meets the rule. A person should check ownership and location count before deciding eligibility.

- **MC-091 IMMIGRANT FOOD** (restaurant, Delicatessen). Candidate, not a participant.
  - Finn: Not independent (Local chain). 4 locations [Source](https://shareourstrength.org/podcast/immigration-policy-on-the-menu-in-dc-restaurants/)
  - AI: Independent, confidence 68%. From memory, Immigrant Food is a DC-founded restaurant concept that opened its first location near the White House at this address, started by local founders and not owned by an outside parent or franchise. As far as I know it has only a handful of DC-area locations, which would meet the five-location limit. My knowledge may be out of date: new locations or outside investment since then could change the result.

- MC-096 JOE & THE JUICE NEW YORK LLC (cafe, Delicatessen). Candidate, not a participant.
  - Finn: Not independent (Franchise). global chain [Source](https://www.joejuice.com/stores)
  - AI: National chain, confidence 90%. Joe & The Juice is a Danish juice and coffee bar chain with hundreds of locations worldwide, and the licensee name points to a New York-based US entity rather than a local DC owner. It fails part 1 of the rule because it is controlled by an operator headquartered outside the DC metro area (it has also been backed by private equity), and it far exceeds five locations. This is based on my training knowledge, which may be out of date, but a change that would make it eligible is very unlikely.

- MC-101 KRISPY KREME DOUGHNUT (cafe, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Franchise). global chain [Source](https://site.krispykreme.com/dc/washington)
  - AI: National chain, confidence 85%. Krispy Kreme is a well-known doughnut chain headquartered in Charlotte, North Carolina, and is a publicly traded company with hundreds of locations. Some Krispy Kreme shops are run by franchisees, which would fail part 3, but I can't tell from the license whether this Connecticut Ave location is company-owned or franchised. Either way it fails the rule, because the brand is controlled from outside the DC metro area (part 1). A reviewer may want to confirm whether this shop is franchised, but that would not change its ineligibility.

- MC-107 Le Pain Quotidien (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Local chain). DC chain, but could be potentially suitors for our expansion [Source](https://www.lepainquotidien.com/us/en)
  - AI: National chain, confidence 85%. Le Pain Quotidien is an international bakery-restaurant chain founded in Belgium. Its US locations were sold in 2020 to Aurify Brands, a New York-based operator, so it is controlled from outside the DC metro area and fails part 1 of the rule. It also has far more than five locations. My knowledge may be out of date, and I cannot confirm this particular address is still open, so a person should check it.

- MC-110 LUKE'S LOBSTER (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Franchise). global chain with around 30 stores [Source](https://lukeslobster.com/pages/shack-locations?srsltid=AU7gw4U_fU10PGFSqAaWFkkv-fhjutQaCmsPGhVR_j0IafZOrJD1aEhS)
  - AI: National chain, confidence 85%. Luke's Lobster is a seafood shack chain founded and headquartered in New York City, with many locations across the US and abroad, and it has received outside investment. That fails part 1 of the rule (headquartered outside the DC metro area) and also part 2 (well over five locations). My knowledge may be out of date, and some locations have historically been franchised, so a reviewer should confirm whether this location is franchised, which would change the label but not eligibility.

- MC-111 Lulus cafe (cafe, Delicatessen). Candidate, not a participant.
  - Finn: Not independent (National chain). lots of locations [Source](https://lulusjuicecafe.com/)
  - AI: Undetermined, confidence 80%. I do not clearly recognize a business called "Lulus cafe" at 1922 I St NW, so I cannot establish who owns it, how many locations it has, or whether it is a franchise. The name alone does not show that it is independent, and I may be confusing it with other businesses that have similar names. A person should verify the ownership and location count before deciding whether it is eligible.

- **MC-118 McReynold's Liquors** (retail, Food Products). Candidate, not a participant.
  - Finn: Independent. only one location [Source](https://www.yelp.com/biz/mcreynolds-liquors-washington)
  - AI: Undetermined, confidence 60%. McReynolds Liquors is a long-standing liquor store in downtown DC, and from memory it appears to be a single-location, locally run business. However, I cannot confirm from my own knowledge who currently owns it or whether the owners have other stores or sister brands, so parts 1 and 2 of the rule cannot be verified. My information may also be out of date. A person should confirm ownership and location count before treating it as eligible.

- MC-120 MORTON'S OF CHICAGO/WASHINGTON SQUARE INC (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Franchise). operates in many states [Source](https://www.mortons.com/view-all-locations/)
  - AI: National chain, confidence 90%. Morton's The Steakhouse is a large national steakhouse chain founded in Chicago, and to my knowledge it is owned by Landry's, which is headquartered in Houston. It has many locations nationwide, so it fails part 1 of the rule (outside-the-DC-area chain). This is based on my own knowledge, which may be out of date, but a change that would make it eligible is very unlikely.

- MC-124 NANDO'S PERI PERI (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Franchise). global chain [Source](https://www.nandosperiperi.com/find?utm_source=p-max&utm_medium=cpc&utm_campaign=wingman%20bogo&utm_content=wingman-bogo&gad_source=1&gad_campaignid=24293335350&gbraid=0AAAAACyDYRPGIwIP87ziUiWzqcFOvZrfi&gclid=CjwKCAjw88HWBhA3EiwAo2Zd6MvqnyY33c6YibgzOJoscNktQ_oD6uqwtlIO3ObSchBLyELBXl_i3xoC13AQAvD_BwE)
  - AI: National chain, confidence 88%. Nando's PERi-PERi is an international restaurant chain that began in South Africa, and its US business runs many locations across several states. It is not a locally headquartered business with five or fewer locations, so it fails part 1 of the rule (outside headquarters and control). The US locations are generally understood to be company-operated rather than franchised, but either way this location is not eligible. This is based on my general knowledge of the brand, not on records for this specific license.

- MC-134 PEET'S COFFEE & TEA (cafe, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Local chain). dc chain [Source](https://www.peets.com/?utm_source=google&utm_medium=cpc&utm_campaign=Tinuiti_PMax_DTC_Evergreen&utm_content=&utm_term=&utm_campaign=23281581250&utm_device=c&utm_content=&utm_term=&utm_keymatch=&utm_adposition=&gad_source=1&gad_campaignid=23277373829&gbraid=0AAAAAD4KhuRa-vGnvwBMZRDBcPGWpSWJn&gclid=CjwKCAjw88HWBhA3EiwAo2Zd6H5Fh-iCeTB7kbdry8TicSTnNLP7dSJTaf0H7D-OqYQek-vLIKOsIRoCv6kQAvD_BwE)
  - AI: National chain, confidence 90%. Peet's Coffee & Tea is a large coffee chain headquartered in California, not in the DC metro area, and it is owned by JDE Peet's, a publicly traded company. That fails part 1 of the rule (local ownership with no outside control). This is based on my own knowledge, which may be out of date, and on the name matching. I can't confirm from the name alone whether this specific location is company-run or a licensed outlet, but it is ineligible either way.

- MC-142 POTBELLY SANDWICH SHOP (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Local chain). many dc locations [Source](https://www.potbelly.com/locations/washington-dc/17th-l)
  - AI: National chain, confidence 93%. Potbelly Sandwich Shop is a well-known sandwich chain headquartered in Chicago with hundreds of locations nationwide. It has been a public company and has recently pursued franchising, so this location could be either company-run or franchised. Either way it fails the rule: it is not locally owned (part 1) and has far more than five locations (part 2). My knowledge of its ownership may be out of date, but no likely change would make it eligible.

- **MC-150 Queenies Nail Salon** (salon, Beauty Shop (Nails)). Candidate, not a participant.
  - Finn: Independent. seems like there is only one store [Source](https://queeniesnailsalon.com/)
  - AI: Undetermined, confidence 85%. I do not recognize a nail salon called Queenies Nail Salon at 1926 I St NW, so I cannot confirm who owns it, how many locations it and any sister brands have, or whether it is a franchise. The name alone suggests a small local shop, but that is not evidence of independence under the rule. A person should check ownership and location count before deciding eligibility.

- **MC-154 Residents** (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Independent. only one location [Source](https://www.residentsdc.com/)
  - AI: Undetermined, confidence 60%. The name resembles Residents Cafe & Bar, a Dupont Circle cafe-bar I believe is a local, single-location business, but I recall it at a different nearby address (18th St NW), so I'm not sure this license is the same business. I also can't confirm its current owners or whether they run sister brands that would exceed five locations. Because of these gaps and the possibility that my information is out of date, ownership and location count can't be established.

- MC-157 Rosetta Bakery (cafe, Bakery). Candidate, not a participant.
  - Finn: Not independent (National chain). operate out of florida amongst other states too [Source](https://www.rosettabakery.com/)
  - AI: Undetermined, confidence 70%. I do not clearly recognize a Rosetta Bakery at this Connecticut Avenue address, so I cannot establish who owns it or how many locations it has. The name matches a Miami-based Italian bakery brand. If this is the same business, it would likely fail part 1 as a chain headquartered outside the DC area, but I am relying on the name alone and cannot confirm the connection. A person should check ownership, headquarters and location count before deciding eligibility.

- MC-158 Roti Restaurant Group LLC (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Franchise). global chain [Source](https://roti.com/pages/locations?gad_campaignid=24239431159&gad_source=1&gbraid=0AAAAADQ2Q7ny9d8Hb0lL5s5c-WuhikGXa&gclid=CjwKCAjw88HWBhA3EiwAo2Zd6KpYJIcvvGNVDJ2iV5QojyUaKrjmbWe8oGGoYDwhDfI4L_XmPVCR8hoC1fcQAvD_BwE&utm_campaign=Brand_WashingtonDC&utm_medium=cpc&utm_source=google)
  - AI: National chain, confidence 85%. Based on my training knowledge, Roti (Roti Modern Mediterranean) is a fast-casual chain headquartered in Chicago with dozens of locations in several states, including multiple in DC. A chain headquartered outside the DC metro area fails part 1 of the rule, and its location count would also exceed five. My information may be out of date, so ownership and headquarters should be confirmed.

- **MC-169 SICHUAN PAVILION** (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Independent. seems to be only one location, but they may have discontinued one in Rockville [Source](https://sichuanpaviliondc.com/)
  - AI: Undetermined, confidence 60%. Sichuan Pavilion is a long-running Chinese restaurant on K Street NW, and from memory it appears to be a single-location business. However, I do not have reliable knowledge of who owns it, whether the owners run other restaurants, or whether there are outside investors. Because ownership and the total location count cannot be confirmed for parts 1 and 2 of the rule, it is marked undetermined. My information may also be out of date.

- MC-178 STARBUCKS CORPORATION (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Franchise). global chain [Source](https://www.starbucks.com/store-locator)
  - AI: National chain, confidence 95%. The license is held by Starbucks Corporation, the publicly traded coffee chain headquartered in Seattle with thousands of locations. Because the operator is headquartered outside the DC metro area, it fails part 1 of the rule. Starbucks locations in this area are generally company-operated rather than franchised, so national_chain is the first failing category that fits.

- **MC-193 TEAISM** (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Local chain). have locations in penn quarter, dupont, etc [Source](https://www.teaism.com/restaurants/dupont-circle/?srsltid=AU7gw4VFLTOmrEGzYEMDTG0DfRh3QrLYT4EiU54n3-1bTHYeUVQlh8RA)
  - AI: Independent, confidence 75%. From my training, Teaism is a DC-founded tea house and restaurant owned by its local founders, with no known outside parent, private-equity or franchise involvement. To my knowledge it has run only a few locations, such as Lafayette Square at this address and Penn Quarter, so it appears to meet all three parts of the rule. My knowledge may be out of date on ownership changes or the current number of locations, so a person should confirm.

- **MC-205 Top Shelf Wine & Liquor** (retail, Cigarette Sales (Retail)). Candidate, not a participant.
  - Finn: Independent. only one location, not to be confused with the separate entity with a similar name in Towson [Source](https://topshelfwineandspirits.com/?gad_source=1&gad_campaignid=21205792045&gbraid=0AAAAACTWAXX4r41AurOfVNvSoNcnUD5bV&gclid=CjwKCAjw88HWBhA3EiwAo2Zd6Makpp--qHZ7jIuw6wFPVITuHZCjpjfGiC9X7IFBdXG1kPHQHOpNQxoCDx4QAvD_BwE)
  - AI: Undetermined, confidence 80%. I do not clearly recognize a business called Top Shelf Wine & Liquor at 1033 Connecticut Ave NW, and the name is generic enough that it could match unrelated stores elsewhere. Going on the name alone, I cannot establish who owns it, whether it is a franchise, or how many locations it and any sister brands have, so none of the three parts of the rule can be confirmed. A person should check its ownership and location count.

- **MC-215 VOORTHUIS OPTICIAN INC** (retail, General Business). Candidate, not a participant.
  - Finn: Not independent (Local chain). eye care company [Source](https://voorthuis.com/locations/)
  - AI: Independent, confidence 70%. From my training, Voorthuis Opticians is a long-standing, family-owned optical shop in downtown DC, and I know of no outside parent, franchise, or investor ownership. As far as I know it has only one or a few locations, which meets the five-location limit. My knowledge may be out of date, since a sale or expansion could have happened, so a person should confirm current ownership and the location count.

- **MC-216 Wall's Barber Shop** (salon, Barber Shop). Candidate, not a participant.
  - Finn: Independent. only one location [Source](https://www.downtowndc.org/explore/places/walls-barber-shop/)
  - AI: Undetermined, confidence 80%. I do not have reliable knowledge of a barber shop called Wall's Barber Shop at 1660 L St NW, so I cannot confirm who owns it, how many locations it has, or whether it is a franchise. The name sounds like a small local shop, but the name alone is not enough to show it meets the rule. A person should verify ownership and location count before deciding eligibility.

- MC-217 WAWA, INC. (restaurant, General Business). Candidate, not a participant.
  - Finn: Not independent (Franchise). lots of locations around the East Coast [Source](https://www.wawa.com/iframe/store-info)
  - AI: National chain, confidence 95%. Wawa, Inc. is a large convenience store and gas station chain headquartered in Pennsylvania with roughly a thousand locations, and the license is held by the corporate entity itself. It fails part 1 of the rule because it is run by a company headquartered outside the DC metro area, and it also far exceeds five locations. Wawa is company-operated rather than franchised, so national chain is the right label.

## Holdout

Run 2026-10-15T18:27:39-04:00 · model `claude-opus-5-5` · prompt [`458e8c2d`](../../resources/prompts/merchant-eligibility.md) · 20 cases · The one holdout run, with frozen prompt 458e8c2d.

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
  - Finn: Not independent (Franchise). global chain [Source](https://shakeshack.com/?utm_source=google&utm_medium=paidsearch&utm_campaign=search_br_conversion_go_text_x_exact&utm_adgroup=conversion_x_menu_br&utm_keyword=shake%20shack%20near%20me&utm_content=kwd-311927045454&gad_source=1&gad_campaignid=10936666222&gbraid=0AAAAADRyPRXLA0aD49m6h8MWoaGCXqejA&gclid=CjwKCAjw88HWBhA3EiwAo2Zd6PAADvqtbjF-wM6x9f4e2kMhsZPa9DBoMQ0GqKHuw66LsoQosIgPExoCyQ0QAvD_BwE)
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

## Run history

| Run | Split | Prompt | Cases | Agreement | Independent found | Eligible calls right | Note |
|---|---|---|---|---|---|---|---|
| [2026-10-15T18:13:38-04:00](merchant-runs/2026-10-15-claude-opus-5-5-prompt-1abdea31-dev.md) | dev | `1abdea31` | 40 | 70% | 2 of 11 | 2 of 5 | First dev run, prompt unchanged. |
| [2026-10-15T18:21:53-04:00](merchant-runs/2026-10-15-claude-opus-5-5-prompt-458e8c2d-dev.md) | dev | `458e8c2d` | 40 | 70% | 2 of 11 | 2 of 5 | Prompt change 1 (dev only): independent only when sure of local ownership and 5 or fewer locations. |
| [2026-10-15T18:27:39-04:00](merchant-runs/2026-10-15-claude-opus-5-5-prompt-458e8c2d-holdout.md) | holdout | `458e8c2d` | 20 | 85% | 0 of 3 | n/a | The one holdout run, with frozen prompt 458e8c2d. |
