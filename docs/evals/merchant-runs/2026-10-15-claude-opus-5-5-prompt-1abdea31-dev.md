# Merchant eval run: Dev, prompt `1abdea31`

> Every business named here is a candidate, not a Local Goods participant. Judged from the AI's own training knowledge and the public license facts shown. It did not search the web, so it may not know about recent sales, closures or new owners.

Run 2026-10-15T18:13:38-04:00 · model `claude-opus-5-5` · prompt [`1abdea31`](../../../resources/prompts/merchant-eligibility.md) · 40 cases · First dev run, prompt unchanged.

| Measure | Result |
|---|---|
| Eligible yes/no agreement | 28 of 40 (70%, 95% CI 54.6–81.9%) |
| Baseline: always answer "not eligible" | 29 of 40 (72.5%, 95% CI 57.2–83.9%) |
| Of Finn's independent businesses, AI called eligible | 2 of 11 (18.2%, 95% CI 5.1–47.7%) |
| Of the AI's eligible calls, Finn labeled independent | 2 of 5 (40%, 95% CI 11.8–76.9%) |
| Exact classification match | 12 of 40 (30%, 95% CI 18.1–45.4%) |
| AI said undetermined on a case Finn decided | 12 of 40 (30%, 95% CI 18.1–45.4%) |
| Finn undetermined: AI also said undetermined | n/a: no case is labeled undetermined |

Confusion matrix (rows: Finn, columns: AI):

| Finn ↓ / AI → | Eligible | Not eligible | Undetermined |
|---|---|---|---|
| Independent | 2 | 0 | 9 |
| Not independent | 3 | 23 | 3 |
| Undetermined | 0 | 0 | 0 |

By the reason Finn gave (not independent cases):

| Reason | Cases | AI said not eligible | AI gave the same reason | AI undetermined | AI called eligible |
|---|---|---|---|---|---|
| Local chain | 10 | 5 of 10 (50%, 95% CI 23.7–76.3%) | 2 of 10 (20%, 95% CI 5.7–51%) | 2 | 3 |
| Franchise | 12 | 12 of 12 (100%, 95% CI 75.8–100%) | 3 of 12 (25%, 95% CI 8.9–53.2%) | 0 | 0 |
| National chain | 7 | 6 of 7 (85.7%, 95% CI 48.7–97.4%) | 5 of 7 (71.4%, 95% CI 35.9–91.8%) | 1 | 0 |
| Outside control | 0 | n/a (none) | n/a (none) | 0 | 0 |

Disagreements (28): every case where the AI's classification differs from Finn's. **Bold** means the eligible yes/no answer differs too.

- **MC-024 Bully** (restaurant, General Business). Candidate, not a participant.
  - Finn: Independent. only one location [Source](https://www.bullywdc.com/)
  - AI: Undetermined, confidence 80%. I do not clearly recognize a business called "Bully" at 2033 M St NW, so I cannot say who owns it, how many locations it has, or whether it is a franchise. The name alone is not enough to judge any part of the rule, and the license activity ("General Business" for a restaurant) adds no ownership information. A person should confirm the owner and any other locations before deciding eligibility.

- MC-032 CAVA MEZZE GRILL, LLC. (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (National chain). operates in every state [Source](https://cava.com/locations#DC)
  - AI: Outside control, confidence 85%. From my training knowledge, CAVA is the Washington, DC–headquartered Mediterranean restaurant brand operated by CAVA Group, which became a publicly traded company in 2023 and runs hundreds of company-owned locations nationwide. Because it is headquartered in the DC area but controlled by a public company, it fails part 1 of the rule (it would also fail the five-location limit). I am matching on the trade name and have not confirmed this license belongs to CAVA Group, and my information may be out of date, so a person should verify it.

- MC-036 Chipotle Mexican Grill (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Franchise). many locations [Source](https://locations.chipotle.com/dc)
  - AI: National chain, confidence 95%. Chipotle Mexican Grill is a nationally known restaurant chain with thousands of locations. It is a publicly traded company headquartered outside the DC metro area (Newport Beach, California), and it operates its restaurants itself rather than franchising them in the US. It fails part 1 of the rule (headquartered outside DC with public-company control), so it is classified as a national chain.

- MC-045 Colada Shop Dupont LLC. (restaurant, General Business). Candidate, not a participant.
  - Finn: Not independent (Local chain). 7 locations in DC [Source](https://www.coladashop.com/locations)
  - AI: Undetermined, confidence 60%. Colada Shop is a Cuban cafe brand I recognize as founded and based in the DC area, with several locations around the region (e.g., 14th Street, The Wharf, and Northern Virginia). From memory alone, I can't reliably confirm the current total of locations including any sister brands, which may be near or above the five-location limit in part 2. I also can't confirm there is no outside investor control under part 1, and my knowledge may be out of date. A person should verify the current location count and ownership.

- **MC-071 FILTER COFFEEHOUSE** (restaurant, Delicatessen). Candidate, not a participant.
  - Finn: Independent. one location [Source](https://www.filtercoffeehouse.com/)
  - AI: Undetermined, confidence 60%. From memory, Filter Coffeehouse is a small DC-based coffee shop brand with a few locations, including Dupont Circle and Foggy Bottom, and I am not aware of any franchise or outside ownership. However, I cannot confirm its current owners or how many locations it and any sister brands have today, and my knowledge may be out of date. Because the ownership and location-count parts of the rule cannot be firmly established, it is marked undetermined.

- **MC-084 HAY ADAMS HOTEL** (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Independent. one location [Source](https://www.hayadams.com/location/)
  - AI: Undetermined, confidence 60%. This is the Hay-Adams, a historic single-property luxury hotel across from Lafayette Square, whose licensed restaurant operations include its dining room and the Off the Record bar. I believe it is a standalone hotel rather than part of a chain, but I cannot reliably confirm from memory who its current owners are or whether any outside investor or parent company controls it, so part 1 of the rule cannot be settled. My knowledge may also be out of date, so a person should verify the current ownership.

- MC-086 Henry's Salon (salon, Barber Shop). Candidate, not a participant.
  - Finn: Not independent (Local chain). three stores [Source](https://henrybarber.shop/)
  - AI: Undetermined, confidence 85%. I do not clearly recognize a business called Henry's Salon at 1924 I St NW, so I cannot confirm who owns it, how many locations it has, or whether it is a franchise. The name sounds local, but that alone is not evidence of independence under the rule. A person should verify ownership and location count before deciding eligibility.

- **MC-091 IMMIGRANT FOOD** (restaurant, Delicatessen). Candidate, not a participant.
  - Finn: Not independent (Local chain). 4 locations [Source](https://shareourstrength.org/podcast/immigration-policy-on-the-menu-in-dc-restaurants/)
  - AI: Independent, confidence 60%. Immigrant Food is a DC-founded restaurant concept started by local entrepreneurs, and its original location is at 1701 Pennsylvania Ave NW near the White House. As far as I know it is locally owned, not a franchise, and has no outside parent, private-equity or public-company control. It has added a few locations, but I believe it is still at five or fewer. My knowledge may be out of date, and I am unsure whether partners' other restaurants would count as sister brands, so the location count under part 2 should be checked.

- MC-096 JOE & THE JUICE NEW YORK LLC (cafe, Delicatessen). Candidate, not a participant.
  - Finn: Not independent (Franchise). global chain [Source](https://www.joejuice.com/stores)
  - AI: National chain, confidence 92%. Joe & The Juice is a Danish-founded juice and coffee bar chain with hundreds of locations worldwide, and the licensee name 'Joe & The Juice New York LLC' indicates this location is operated by the chain's US entity rather than a DC-area owner. It is not headquartered in the DC metro area, so it fails part 1 of the rule (and also far exceeds five locations). This is based on my training knowledge, which may be out of date, but a change that would make it independent is very unlikely.

- MC-101 KRISPY KREME DOUGHNUT (cafe, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Franchise). global chain [Source](https://site.krispykreme.com/dc/washington)
  - AI: National chain, confidence 85%. Krispy Kreme is a well-known doughnut chain with hundreds of locations, headquartered in Charlotte, North Carolina, and publicly traded. That fails part 1 of the rule (and part 2), so this location is not eligible. I can't confirm from memory whether this Connecticut Ave shop is company-owned or run by a franchisee; if it is a franchise, the classification would be franchise instead, but it is ineligible either way.

- MC-107 Le Pain Quotidien (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Local chain). DC chain, but could be potentially suitors for our expansion [Source](https://www.lepainquotidien.com/us/en)
  - AI: National chain, confidence 85%. Le Pain Quotidien is an international bakery-restaurant chain founded in Belgium. Its US locations have been operated by companies headquartered outside the DC metro area, so it fails part 1 of the rule. My memory of its ownership may be out of date, since the US business changed hands around 2020, but no version I know of is a DC-area independent.

- MC-110 LUKE'S LOBSTER (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Franchise). global chain with around 30 stores [Source](https://lukeslobster.com/pages/shack-locations)
  - AI: National chain, confidence 85%. Luke's Lobster is a seafood shack chain founded and headquartered in New York City, with many locations across the U.S. and abroad. That means it fails part 1 of the rule (not headquartered in the DC metro area), and it also has far more than five locations. This is based on my training knowledge, which may be out of date regarding current ownership (the company has reportedly had outside investment), so a quick check is advised, though it would not be eligible either way.

- MC-111 Lulus cafe (cafe, Delicatessen). Candidate, not a participant.
  - Finn: Not independent (National chain). lots of locations [Source](https://lulusjuicecafe.com/)
  - AI: Undetermined, confidence 80%. I do not recognize a business called Lulus cafe at 1922 I St NW from my own knowledge, so I cannot establish who owns it, how many locations it has, or whether it is a franchise. The name alone does not show that it is independent, and not knowing of other locations is not evidence that there are none. A person should check its ownership and location count before deciding eligibility.

- **MC-118 McReynold's Liquors** (retail, Food Products). Candidate, not a participant.
  - Finn: Independent. only one location [Source](https://www.yelp.com/biz/mcreynolds-liquors-washington)
  - AI: Undetermined, confidence 75%. McReynolds Liquors appears to be a long-standing liquor and wine shop on G Street NW near the White House. I believe it is a single-location local business, but I cannot confirm its current owners, whether there is any outside control, or whether it has sister stores. This is based on memory that may be out of date, so ownership cannot be established well enough to call it independent.

- MC-120 MORTON'S OF CHICAGO/WASHINGTON SQUARE INC (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Franchise). operates in many states [Source](https://www.mortons.com/view-all-locations/)
  - AI: National chain, confidence 90%. This appears to be a location of Morton's The Steakhouse, a national steakhouse chain founded in Chicago with dozens of locations, owned by Landry's (headquartered in Houston, Texas), based on what I know from training. Because it belongs to a chain headquartered outside the DC metro area, it fails part 1 of the rule. My ownership information may be out of date, but a change that would make this location independent is very unlikely.

- MC-124 NANDO'S PERI PERI (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Franchise). global chain [Source](https://www.nandosperiperi.com/find)
  - AI: National chain, confidence 85%. Nando's PERi-PERi is an international restaurant chain founded in South Africa, and from my knowledge its US locations, including those in the DC area, are run by the company's US arm (Nando's Restaurant Group) rather than a local independent owner. It has far more than five locations, and its parent is headquartered outside the DC metro area, so it fails part 1 of the rule. I recognize the brand name and am relying on memory that may be out of date, but I am not aware of this being a franchised location; either way it would not be eligible.

- MC-134 PEET'S COFFEE & TEA (cafe, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Local chain). dc chain [Source](https://www.peets.com/)
  - AI: National chain, confidence 93%. Peet's Coffee is a large coffee chain headquartered in Emeryville, California, with hundreds of locations. It is owned by JDE Peet's, a publicly traded company based in the Netherlands. That fails part 1 of the rule because it is run from outside the DC metro area. Peet's company-operated cafes are not generally franchised, but a person should confirm this specific location's operator, since my knowledge may be out of date.

- MC-142 POTBELLY SANDWICH SHOP (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Local chain). many dc locations [Source](https://www.potbelly.com/locations/washington-dc/17th-l)
  - AI: National chain, confidence 88%. Potbelly Sandwich Shop is a well-known sandwich chain headquartered in Chicago with hundreds of locations, and it was a publicly traded company. It has also been selling franchises, so this particular location could be franchisee-run, but either way it fails the rule. Most Potbelly shops have been company-operated, so I classified it as a national chain headquartered outside the DC area (part 1). My knowledge of its ownership may be out of date, but nothing that could have changed would make it eligible.

- **MC-150 Queenies Nail Salon** (salon, Beauty Shop (Nails)). Candidate, not a participant.
  - Finn: Independent. seems like there is only one store [Source](https://queeniesnailsalon.com/)
  - AI: Undetermined, confidence 85%. I do not clearly recognize a business called Queenies Nail Salon at 1926 I St NW, so I cannot say who owns it or how many locations it has. The name sounds like a small local shop, but a name alone is not evidence that it meets the ownership, location-count or no-franchise parts of the rule. A person should confirm the owner and any other locations before treating it as eligible.

- **MC-154 Residents** (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Independent. only one location [Source](https://www.residentsdc.com/)
  - AI: Undetermined, confidence 60%. The name matches Residents, a cafe and bar I recall in the Dupont Circle area, and it appears to be a single-location local business, but I am not certain of its owners or whether there are sister brands. The licensed address may also not match the one I remember, so I can't be sure it is the same business. Without clear ownership information, the independence rule cannot be confirmed, and my knowledge may be out of date.

- MC-158 Roti Restaurant Group LLC (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Franchise). global chain [Source](https://roti.com/pages/locations)
  - AI: National chain, confidence 80%. This appears to be Roti Mediterranean Grill (Roti Modern Mediterranean), a fast-casual chain founded in DC but which, as I recall, moved its headquarters to Chicago and has operated dozens of locations in several states. That would fail part 1 (headquartered outside the DC metro area), and it also far exceeds the five-location limit in part 2. I am matching on the corporate name and my memory may be out of date regarding its headquarters or ownership, so a person should confirm.

- **MC-169 SICHUAN PAVILION** (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Independent. seems to be only one location, but they may have discontinued one in Rockville [Source](https://sichuanpaviliondc.com/)
  - AI: Undetermined, confidence 70%. I recognize Sichuan Pavilion as a longtime Chinese restaurant on K Street NW downtown, but I do not have reliable knowledge of who owns it, whether it has sister locations, or whether ownership has changed. Without that, I cannot confirm the ownership, location-count or franchise parts of the rule. A person should verify ownership from public records before deciding eligibility.

- MC-178 STARBUCKS CORPORATION (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Franchise). global chain [Source](https://www.starbucks.com/store-locator)
  - AI: National chain, confidence 95%. The license names Starbucks Corporation, the Seattle-headquartered public company that operates thousands of coffee shops. Because the licensee is the corporation itself, this appears to be a company-operated store rather than a franchise, so it fails part 1 of the rule (headquartered outside the DC metro area). It also far exceeds the five-location limit. Note that the ZIP code 20007 is unusual for an L St NW address (that block is normally 20036), so the address may be worth a quick check, but it does not change the outcome.

- **MC-193 TEAISM** (restaurant, Restaurant). Candidate, not a participant.
  - Finn: Not independent (Local chain). have locations in penn quarter, dupont, etc [Source](https://www.teaism.com/restaurants/dupont-circle/)
  - AI: Independent, confidence 70%. From my training knowledge, Teaism is a small DC-founded and DC-owned tea house and casual restaurant group, with only a handful of locations (such as Dupont Circle, Penn Quarter and Lafayette Square). I am not aware of any franchising, outside parent company or private-equity ownership, so it appears to meet all three parts of the rule. My information may be out of date, though: locations have opened and closed over the years and ownership could have changed. A person should confirm the current location count and ownership before relying on this.

- **MC-205 Top Shelf Wine & Liquor** (retail, Cigarette Sales (Retail)). Candidate, not a participant.
  - Finn: Independent. only one location, not to be confused with the separate entity with a similar name in Towson [Source](https://topshelfwineandspirits.com/)
  - AI: Undetermined, confidence 85%. I do not specifically recognize a business called Top Shelf Wine & Liquor at 1033 Connecticut Ave NW, and the name is generic enough that it could match unrelated stores elsewhere. I can't establish who owns it, how many locations it or any sister stores have, or whether it is a franchise, so none of the three parts of the rule can be confirmed. A person should check its ownership and location count from public records before deciding eligibility.

- **MC-215 VOORTHUIS OPTICIAN INC** (retail, General Business). Candidate, not a participant.
  - Finn: Not independent (Local chain). eye care company [Source](https://voorthuis.com/locations/)
  - AI: Independent, confidence 75%. From my training, Voorthuis Opticians is a long-standing, family-run optical shop on Connecticut Avenue in downtown DC, and I know of no other locations, franchise ties or outside owners. That meets all three parts of the rule: local ownership, five or fewer locations, and not a franchise. My knowledge may be out of date, since ownership or locations could have changed, so please confirm current ownership before relying on this.

- **MC-216 Wall's Barber Shop** (salon, Barber Shop). Candidate, not a participant.
  - Finn: Independent. only one location [Source](https://www.downtowndc.org/explore/places/walls-barber-shop/)
  - AI: Undetermined, confidence 80%. I do not clearly recognize a barber shop called Wall's Barber Shop at 1660 L St NW, so I cannot establish who owns it, how many locations it has, or whether it is a franchise. The name sounds like it could be a local business, but that alone is not evidence of independence under the rule. A person should confirm ownership and location count before deciding eligibility.

- MC-217 WAWA, INC. (restaurant, General Business). Candidate, not a participant.
  - Finn: Not independent (Franchise). lots of locations around the East Coast [Source](https://www.wawa.com/iframe/store-info)
  - AI: National chain, confidence 95%. Wawa, Inc. is a large convenience store and gas station chain headquartered in Pennsylvania with hundreds of locations across several states. The license is in the company's own name, so this store appears to be company-operated rather than franchised. Because its headquarters is outside the DC metro area, it fails part 1 of the rule, and it also far exceeds the five-location limit.
