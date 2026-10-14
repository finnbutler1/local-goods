# Redemption Fraud Detection eval results

**Headline: Caught 17 of 26 suspicious cases; 17 of 19 holds were suspicious.**

Measured on 50 unseen cases, 26 of them labeled suspicious by a person, with the current prompt. 95% confidence intervals: catch rate 46.2–80.6%, hold precision 68.6–97.1%; overall agreement 78% (39 of 50, 64.8–87.2%).

From the run on October 14, 2026 (prompt `8d5e1e70`, `holdout_3` cases, [details](runs/2026-10-14-claude-opus-5-5-prompt-8d5e1e70-cases-33b70229.md)). The AI misses more often than it raises false alarms. It cleared 9 of the 26 suspicious cases, mostly ones with a single strong signal (a big jump over the employee's usual spend, or nearly the whole balance) at normal hours and inside the category's typical range; 4 of the 9 were at the salon. A cleared redemption goes through without a person looking at it, so a miss is the costlier mistake. Holds are reliable: 17 of 19 were suspicious. The 2 false alarms are frequent visitors spending a round amount well above their usual, which the prompt treats as possible credit-to-cash collusion; a held redemption waits for a person, so a false alarm costs a delay, not a wrong payout.

**Locked before the AI saw them:** the 50 `holdout_3` cases were labeled by hand in commit `5f594c1` and locked in [`data/fraud_eval_lock.json`](../../data/fraud_eval_lock.json) (SHA-256 of every row, combined `e8799c6d6407`). `fraud:eval` refuses to run if any locked row changes.

## Misses and false alarms by pattern (`holdout_3`)

Tags are worked out from each case's facts only, never from the person's label or the AI's answer. A case can have several tags. *Missed*: labeled suspicious, AI cleared. *False alarm*: labeled normal, AI held. The "why" comes from the AI's own explanations.

| Pattern | Cases | Labeled suspicious | Missed | False alarms | Why |
|---|---|---|---|---|---|
| Round amount ($20+, whole $10s) | 21 | 18 | 3 | 1 | A round amount is one weak signal to the AI: it cleared round amounts inside the category's normal range (FE-58, FE-89) or on part of the balance mid-afternoon (FE-86), and held one from a very frequent visitor (FE-75). |
| 3x or more the usual amount | 20 | 18 | 3 | 2 | The AI called a big jump over usual spend "worth watching" but not enough alone when the amount was not round, used part of the balance and came in business hours (FE-45, FE-86, FE-89); with very frequent visits it held (FE-62, FE-75). |
| Uses 90%+ of the balance | 15 | 15 | 2 | 0 | The AI let one recent visit to the merchant and a normal-for-the-category amount outweigh using nearly the whole balance (FE-58, FE-71). |
| New merchant (0 visits in 30 days) | 16 | 15 | 2 | 0 | The AI read "no repeat visits" as reassuring, because the collusion pattern it looks for is repeat visits, so a first visit with a big amount was cleared (FE-45, FE-86). |
| Outside business hours | 11 | 8 | 0 | 1 | The one false alarm (FE-62) was a 6:40 AM coffee purchase; the early hour added to the frequent-visitor signal. |
| 5+ visits here in 7 days | 3 | 0 | 0 | 2 | Both false alarms (FE-62, FE-75): the AI reads many visits in a week plus a much larger round amount as possible collusion, where the person saw a loyal regular. Same pattern as the earlier holdout_2 false alarms. |
| Above the category's typical range | 21 | 19 | 3 | 1 | Slightly above the range but not round and during the day, so the AI saw one signal and cleared it (FE-45, FE-56, FE-86); with frequent early visits it held (FE-62). |

| Merchant category | Cases | Labeled suspicious | Missed | False alarms | Why |
|---|---|---|---|---|---|
| cafe | 7 | 3 | 1 | 1 | Missed FE-86 (a first visit at 4 times the usual spend, at 3 PM); false alarm FE-62 (a regular at 6:40 AM spending 4 times the usual). |
| fitness | 7 | 2 | 0 | 0 |  |
| restaurant | 18 | 8 | 2 | 1 | Missed FE-56 and FE-80 (about 2.5–2.8 times the usual at dinner time, read as a larger dinner); false alarm FE-75 (a very frequent visitor, round $40). |
| retail | 11 | 7 | 2 | 0 | Missed FE-45 (6.5 times the usual but under half the balance, at 1 PM) and FE-71 (nearly the whole $50 balance, but a recent visit and a normal retail amount). |
| salon | 7 | 6 | 4 | 0 | 4 of 6 suspicious salon cases were missed (FE-50, FE-58, FE-74, FE-89): the salon's wide $25–$90 typical range made amounts 2–3.5 times the employee's usual look normal to the AI. |

## Latest run

> **Note on this run:** Prompt unchanged (8d5e1e70). Adds 50 holdout_3 cases, labeled by hand and locked at commit 5f594c1 before the AI saw them; 26 are labeled suspicious.

| | |
|---|---|
| Run | October 14, 2026 4:01 PM GMT-0400 |
| Model | `claude-opus-5-5` |
| Prompt version | `8d5e1e70` (first 8 characters of the SHA-256 of [`redemption-fraud-detection.md`](../../resources/prompts/redemption-fraud-detection.md)) |
| Cases | 90, version `33b70229` hand-labeled synthetic redemptions ([`data/fraud_eval_cases.csv`](../../data/fraud_eval_cases.csv)) |

## Agreement with the human labels

| Split | Cases (labeled suspicious) | Agreement | Catch rate: suspicious cases the AI held | Hold precision: holds that were suspicious |
|---|---|---|---|---|
| **Holdout 3** (labeled and locked after the last prompt change, never seen while changing it) | 50 (26) | 78% (39/50) | **17 of 26 (65.4%, 95% CI 46.2–80.6%)** | **17 of 19 (89.5%, 95% CI 68.6–97.1%)** |
| Holdout 2 (labeled after the last prompt change, never seen while changing it) | 10 (4) | 80% (8/10) | 4 of 4 (100%, 95% CI 51–100%) | 4 of 6 (66.7%, 95% CI 30–90.3%) |
| Holdout (not used to change the prompt) | 10 (4) | 90% (9/10) | 4 of 4 (100%, 95% CI 51–100%) | 4 of 5 (80%, 95% CI 37.6–96.4%) |
| Dev (may be used to improve the prompt) | 20 (7) | 90% (18/20) | 7 of 7 (100%, 95% CI 64.6–100%) | 7 of 9 (77.8%, 95% CI 45.3–93.7%) |
| All cases | 90 (41) | 82.2% (74/90) | 32 of 41 (78%, 95% CI 63.3–88%) | 32 of 39 (82.1%, 95% CI 67.3–91%) |

The AI's `hold` counts as *suspicious* and `clear` as *normal*.

## Confusion matrices

Rows are the human label, columns are the AI's call.

**Dev**

| Human ↓ / AI → | normal (clear) | suspicious (hold) |
|---|---|---|
| normal | 11 | 2 (false alarm) |
| suspicious | 0 (missed) | 7 |

**Holdout**

| Human ↓ / AI → | normal (clear) | suspicious (hold) |
|---|---|---|
| normal | 5 | 1 (false alarm) |
| suspicious | 0 (missed) | 4 |

**Holdout 2**

| Human ↓ / AI → | normal (clear) | suspicious (hold) |
|---|---|---|
| normal | 4 | 2 (false alarm) |
| suspicious | 0 (missed) | 4 |

**Holdout 3**

| Human ↓ / AI → | normal (clear) | suspicious (hold) |
|---|---|---|
| normal | 22 | 2 (false alarm) |
| suspicious | 9 (missed) | 17 |

## Disagreements (16)

| Case | Split | Redemption | Human | Human notes | AI | AI explanation |
|---|---|---|---|---|---|---|
| FE-08 | dev | $40.00 at Farragut Grind, 13:00 | normal | regular customer | hold (85) | This is a round $40.00 charge at a cafe, more than six times this employee's usual $6.40 spend and well above the normal $3–$15 cafe ticket. The employee has also made 9 visits to this same cafe in the past 7 days (22 in 30 days). |
| FE-15 | dev | $100.00 at Connecticut Noodle Bar, 14:00 | normal | treating oneself | hold (88) | This $100.00 redemption is a round amount, about 7 times the employee's usual $13.87 spend, and well above the typical $10–$45 restaurant ticket. It also follows 6 visits to the same merchant in the past 7 days (14 in 30 days). |
| FE-21 | holdout | $60.00 at Press Room Deli, 12:30 | normal | previous customer making a bigger purchase | hold (82) | This $60.00 restaurant redemption is a round amount, about 4.3 times the employee's usual $13.87 spend, and above the normal $10–$45 restaurant ticket. The employee has also made 5 prior redemptions at this same merchant in the last 7 days (11 in 30 days), so several signals point the same way. |
| FE-34 | holdout_2 | $50.00 at Press Room Deli, 12:10 | normal | large amount but has visited here previously and the account is not drained | hold (82) | This is a round $50.00 charge, about 4 times the employee's usual $12.00 spend and above the normal $10–$45 restaurant ticket. It also follows 7 prior visits to the same restaurant in the last 7 days (15 in 30 days), so several signals point to possible collusion. |
| FE-38 | holdout_2 | $20.00 at Farragut Grind, 09:15 | normal | recurring customer at a reasonable time in the morning | hold (72) | Several signals point the same way: an exactly $20.00 charge at a cafe is above the normal $3–$15 ticket and about 3.5 times this employee's usual $5.80 spend, and this is their 4th visit to the same cafe in 7 days (10th in 30). On its own this would be a small amount, but the round, inflated figure combined with frequent repeat visits warrants a review. |
| FE-45 | holdout_3 | $95.00 at Page & Quill Books, 13:30 | suspicious | no visits and way past median amount | clear (58) | The $95.00 retail purchase is about 6.5 times this employee's usual $14.50 spend and somewhat above the typical $10–$80 retail range. However, it is not a round amount, uses less than half of the $210.00 balance, and happened at 1 PM with no repeat visits, so it does not warrant a hold. |
| FE-50 | holdout_3 | $65.00 at K Street Cuts, 10:30 | suspicious | almost 3x the median amount | clear (25) | This $65.00 salon redemption is about three times the employee's usual $22.00 spend, but it falls within the normal $25–$90 range for a salon visit. It also happened at 10 AM, is not a round amount, and uses only part of the $190.00 balance, so there is no pattern of abuse. |
| FE-56 | holdout_3 | $52.30 at Seventeenth Street Kitchen, 19:45 | suspicious | 3x median amount with little visits | clear (45) | This $52.30 restaurant bill is about 2.8 times the employee's usual $18.40 and somewhat above the typical $10–$45 restaurant range, but it is not a round amount, uses well under the $175.00 balance, and happened at 7 PM. It looks consistent with a larger dinner rather than collusion. |
| FE-58 | holdout_3 | $40.00 at K Street Cuts, 08:30 | suspicious | drains balance and double the median amount | clear (55) | This $40.00 salon redemption is a round amount that uses nearly all of the $41.00 balance and is twice the employee's usual $20.00 spend. However, the employee has visited this merchant in the past 30 days, the amount is normal for a salon, and it happened during business hours, so it does not rise to a hold. |
| FE-62 | holdout_3 | $25.00 at Farragut Grind, 06:40 | normal | frequent visitor | hold (78) | Several signals point the same way: this $25.00 cafe redemption is about 4 times the employee's usual $6.20, above the normal $3–$15 cafe ticket, and made at 6 AM, outside business hours. It also follows 6 prior visits to the same merchant in the last 7 days (14 in 30 days). |
| FE-71 | holdout_3 | $48.00 at Page & Quill Books, 11:15 | suspicious | drains account | clear (45) | This $48.00 retail purchase uses nearly all of the $50.00 balance and is about twice the employee's usual $25.00. However, the employee visited this merchant once in the past 30 days, the amount is not round, it falls within the normal range for retail, and it happened at 11 a.m., so it does not warrant a hold. |
| FE-74 | holdout_3 | $55.00 at K Street Cuts, 12:00 | suspicious | 2x the median with little past redemptions and a large chunk of the balance being taken out | clear (20) | This $55.00 salon redemption is within the normal $25.00–$90.00 range for salons, uses about a third of the $160.00 balance, and happened at midday. It is about 2.3 times this employee's usual $24.00 spend, but that is the only signal and is expected for a salon visit. |
| FE-75 | holdout_3 | $40.00 at Press Room Deli, 13:05 | normal | even though 4x the median, they have enough past redemptions and prior visits to be seen as normal | hold (74) | This is a round $40.00 redemption, about 3.6 times the employee's usual $11.00 spend, at a restaurant they have already used 6 times in the past week and 13 times in the past month. The amount is within a normal restaurant bill and the time (1 PM) is ordinary, but the round amount, the jump above usual spending and the very frequent repeat visits together warrant a review. |
| FE-80 | holdout_3 | $41.20 at Seventeenth Street Kitchen, 20:40 | suspicious | not enough past visits with 2.5x past the median | clear (25) | This $41.20 restaurant redemption is about 2.45 times the employee's usual $16.80, but it falls within the normal range for a restaurant, is not a round amount, uses only part of the $72.00 balance, and happened at 8 PM. One elevated signal on its own is not enough to hold. |
| FE-86 | holdout_3 | $30.00 at Farragut Grind, 15:30 | suspicious | no past visits with the amount being 4x the median | clear (62) | This $30.00 cafe redemption is a round amount, four times the employee's usual $7.50, and above the typical $3.00–$15.00 cafe ticket, so it is worth watching. It uses only a quarter of the $120.00 balance and happened at 3 PM with no repeat visits, so it does not reach the hold threshold. |
| FE-89 | holdout_3 | $90.00 at K Street Cuts, 17:00 | suspicious | high amount and significantly above the median with little past redemptions | clear (35) | The $90.00 salon charge is a round number and about 3.5 times this employee's usual $26.00 spend, but it falls within the normal range for a salon visit, uses only part of the $250.00 balance, and happened during business hours. These mild signals together are not enough to hold the payout. |

## Every case

| Case | Split | Redemption | Human | AI | Score | Agree | Pattern tags |
|---|---|---|---|---|---|---|---|
| FE-01 | dev | $80.00 at Triangle Stems, 10:30 | suspicious | hold | 78 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance |
| FE-02 | dev | $14.20 at Connecticut Noodle Bar, 19:30 | normal | clear | 5 | yes |  |
| FE-03 | dev | $36.50 at Triangle Stems, 18:05 | normal | clear | 25 | yes |  |
| FE-04 | holdout | $9.60 at Press Room Deli, 11:45 | normal | clear | 5 | yes |  |
| FE-05 | dev | $22.80 at Connecticut Noodle Bar, 22:20 | normal | clear | 45 | yes | Outside business hours |
| FE-06 | dev | $120.00 at Seventeenth Street Kitchen, 02:15 | suspicious | hold | 97 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance, Outside business hours, Above the category's typical range |
| FE-07 | dev | $17.40 at Connecticut Noodle Bar, 12:40 | normal | clear | 8 | yes |  |
| FE-08 | dev | $40.00 at Farragut Grind, 13:00 | normal | hold | 85 | **no** | Round amount ($20+, whole $10s), 3x or more the usual amount, 5+ visits here in 7 days, Above the category's typical range |
| FE-09 | holdout | $13.50 at Press Room Deli, 06:45 | normal | clear | 35 | yes | Outside business hours |
| FE-10 | dev | $150.00 at K Street Cuts, 23:15 | suspicious | hold | 95 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance, New merchant (0 visits in 30 days), Outside business hours, Above the category's typical range |
| FE-11 | holdout | $21.35 at Seventeenth Street Kitchen, 13:10 | normal | clear | 12 | yes |  |
| FE-12 | dev | $80.00 at Farragut Grind, 06:10 | suspicious | hold | 95 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance, Outside business hours, Above the category's typical range |
| FE-13 | dev | $11.85 at Press Room Deli, 12:05 | normal | clear | 5 | yes |  |
| FE-14 | dev | $24.99 at Page & Quill Books, 17:30 | normal | clear | 15 | yes | New merchant (0 visits in 30 days) |
| FE-15 | dev | $100.00 at Connecticut Noodle Bar, 14:00 | normal | hold | 88 | **no** | Round amount ($20+, whole $10s), 3x or more the usual amount, 5+ visits here in 7 days, Above the category's typical range |
| FE-16 | holdout | $4.75 at Farragut Grind, 15:20 | normal | clear | 5 | yes |  |
| FE-17 | dev | $200.00 at Lafayette Cycle Studio, 05:30 | suspicious | hold | 92 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance, Outside business hours, Above the category's typical range |
| FE-18 | dev | $64.20 at Page & Quill Books, 12:50 | suspicious | hold | 75 | yes | 3x or more the usual amount, Uses 90%+ of the balance, New merchant (0 visits in 30 days) |
| FE-19 | holdout | $270.00 at Page & Quill Books, 22:45 | suspicious | hold | 97 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance, New merchant (0 visits in 30 days), Outside business hours, Above the category's typical range |
| FE-20 | dev | $28.00 at Lafayette Cycle Studio, 07:10 | normal | clear | 15 | yes |  |
| FE-21 | holdout | $60.00 at Press Room Deli, 12:30 | normal | hold | 82 | **no** | Round amount ($20+, whole $10s), 3x or more the usual amount, 5+ visits here in 7 days, Above the category's typical range |
| FE-22 | holdout | $150.00 at Triangle Stems, 03:05 | suspicious | hold | 95 | yes | Round amount ($20+, whole $10s), Uses 90%+ of the balance, New merchant (0 visits in 30 days), Outside business hours, Above the category's typical range |
| FE-23 | holdout | $85.00 at K Street Cuts, 18:30 | suspicious | hold | 78 | yes | 3x or more the usual amount, Uses 90%+ of the balance, New merchant (0 visits in 30 days) |
| FE-24 | dev | $42.00 at K Street Cuts, 11:00 | normal | clear | 25 | yes | 3x or more the usual amount, New merchant (0 visits in 30 days) |
| FE-25 | holdout | $150.00 at Triangle Stems, 23:40 | suspicious | hold | 95 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance, Outside business hours, Above the category's typical range |
| FE-26 | dev | $90.00 at Seventeenth Street Kitchen, 23:50 | suspicious | hold | 92 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance, Outside business hours, Above the category's typical range |
| FE-27 | dev | $30.00 at Farragut Grind, 08:20 | normal | clear | 45 | yes | Round amount ($20+, whole $10s), Above the category's typical range |
| FE-28 | dev | $40.00 at Lafayette Cycle Studio, 06:00 | normal | clear | 38 | yes | Round amount ($20+, whole $10s), Outside business hours |
| FE-29 | dev | $6.25 at Farragut Grind, 08:35 | normal | clear | 4 | yes |  |
| FE-30 | holdout | $48.60 at Seventeenth Street Kitchen, 20:15 | normal | clear | 45 | yes | 3x or more the usual amount, Above the category's typical range |
| FE-31 | holdout_2 | $18.75 at Page & Quill Books, 12:20 | normal | clear | 10 | yes |  |
| FE-32 | holdout_2 | $8.40 at Farragut Grind, 07:50 | normal | clear | 8 | yes |  |
| FE-33 | holdout_2 | $140.00 at Connecticut Noodle Bar, 21:45 | suspicious | hold | 95 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance, Above the category's typical range |
| FE-34 | holdout_2 | $50.00 at Press Room Deli, 12:10 | normal | hold | 82 | **no** | Round amount ($20+, whole $10s), 3x or more the usual amount, 5+ visits here in 7 days, Above the category's typical range |
| FE-35 | holdout_2 | $200.00 at K Street Cuts, 01:30 | suspicious | hold | 92 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, New merchant (0 visits in 30 days), Outside business hours, Above the category's typical range |
| FE-36 | holdout_2 | $120.00 at Lafayette Cycle Studio, 12:00 | suspicious | hold | 78 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Above the category's typical range |
| FE-37 | holdout_2 | $38.90 at Seventeenth Street Kitchen, 21:50 | normal | clear | 30 | yes |  |
| FE-38 | holdout_2 | $20.00 at Farragut Grind, 09:15 | normal | hold | 72 | **no** | Round amount ($20+, whole $10s), 3x or more the usual amount, Above the category's typical range |
| FE-39 | holdout_2 | $32.00 at Lafayette Cycle Studio, 18:15 | normal | clear | 18 | yes |  |
| FE-40 | holdout_2 | $72.50 at Triangle Stems, 17:40 | suspicious | hold | 75 | yes | 3x or more the usual amount, Uses 90%+ of the balance, New merchant (0 visits in 30 days) |
| FE-41 | holdout_3 | $5.40 at Farragut Grind, 08:05 | normal | clear | 5 | yes |  |
| FE-42 | holdout_3 | $180.00 at K Street Cuts, 21:40 | suspicious | hold | 92 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance, New merchant (0 visits in 30 days), Above the category's typical range |
| FE-43 | holdout_3 | $12.75 at Press Room Deli, 12:20 | normal | clear | 5 | yes |  |
| FE-44 | holdout_3 | $30.00 at Lafayette Cycle Studio, 06:15 | normal | clear | 25 | yes | Round amount ($20+, whole $10s), Outside business hours |
| FE-45 | holdout_3 | $95.00 at Page & Quill Books, 13:30 | suspicious | clear | 58 | **no** | 3x or more the usual amount, New merchant (0 visits in 30 days), Above the category's typical range |
| FE-46 | holdout_3 | $16.90 at Connecticut Noodle Bar, 19:10 | normal | clear | 8 | yes |  |
| FE-47 | holdout_3 | $110.00 at Seventeenth Street Kitchen, 23:30 | suspicious | hold | 92 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance, Outside business hours, Above the category's typical range |
| FE-48 | holdout_3 | $42.00 at Triangle Stems, 17:55 | normal | clear | 25 | yes |  |
| FE-49 | holdout_3 | $60.00 at Farragut Grind, 07:20 | suspicious | hold | 92 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance, Above the category's typical range |
| FE-50 | holdout_3 | $65.00 at K Street Cuts, 10:30 | suspicious | clear | 25 | **no** |  |
| FE-51 | holdout_3 | $70.00 at Press Room Deli, 12:40 | suspicious | hold | 88 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance, New merchant (0 visits in 30 days), Above the category's typical range |
| FE-52 | holdout_3 | $28.00 at Lafayette Cycle Studio, 17:30 | normal | clear | 5 | yes |  |
| FE-53 | holdout_3 | $34.60 at Connecticut Noodle Bar, 20:05 | normal | clear | 25 | yes |  |
| FE-54 | holdout_3 | $150.00 at Page & Quill Books, 04:10 | suspicious | hold | 95 | yes | Round amount ($20+, whole $10s), Uses 90%+ of the balance, New merchant (0 visits in 30 days), Outside business hours, Above the category's typical range |
| FE-55 | holdout_3 | $3.95 at Farragut Grind, 14:45 | normal | clear | 10 | yes |  |
| FE-56 | holdout_3 | $52.30 at Seventeenth Street Kitchen, 19:45 | suspicious | clear | 45 | **no** | Above the category's typical range |
| FE-57 | holdout_3 | $200.00 at Triangle Stems, 12:15 | suspicious | hold | 82 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, New merchant (0 visits in 30 days), Above the category's typical range |
| FE-58 | holdout_3 | $40.00 at K Street Cuts, 08:30 | suspicious | clear | 55 | **no** | Round amount ($20+, whole $10s), Uses 90%+ of the balance |
| FE-59 | holdout_3 | $10.20 at Press Room Deli, 11:50 | normal | clear | 15 | yes | 5+ visits here in 7 days |
| FE-60 | holdout_3 | $120.00 at Lafayette Cycle Studio, 22:30 | suspicious | hold | 85 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, New merchant (0 visits in 30 days), Outside business hours, Above the category's typical range |
| FE-61 | holdout_3 | $21.45 at Connecticut Noodle Bar, 12:35 | normal | clear | 22 | yes |  |
| FE-62 | holdout_3 | $25.00 at Farragut Grind, 06:40 | normal | hold | 78 | **no** | 3x or more the usual amount, Outside business hours, 5+ visits here in 7 days, Above the category's typical range |
| FE-63 | holdout_3 | $32.50 at Page & Quill Books, 18:20 | normal | clear | 15 | yes |  |
| FE-64 | holdout_3 | $85.00 at Seventeenth Street Kitchen, 02:45 | suspicious | hold | 85 | yes | 3x or more the usual amount, Outside business hours, Above the category's typical range |
| FE-65 | holdout_3 | $24.00 at Triangle Stems, 13:10 | normal | clear | 10 | yes | New merchant (0 visits in 30 days) |
| FE-66 | holdout_3 | $230.00 at K Street Cuts, 15:00 | suspicious | hold | 92 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance, New merchant (0 visits in 30 days), Above the category's typical range |
| FE-67 | holdout_3 | $9.35 at Press Room Deli, 07:45 | normal | clear | 15 | yes |  |
| FE-68 | holdout_3 | $35.00 at Lafayette Cycle Studio, 18:45 | normal | clear | 15 | yes |  |
| FE-69 | holdout_3 | $90.00 at Connecticut Noodle Bar, 22:50 | suspicious | hold | 95 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance, New merchant (0 visits in 30 days), Outside business hours, Above the category's typical range |
| FE-70 | holdout_3 | $7.80 at Farragut Grind, 09:25 | normal | clear | 10 | yes |  |
| FE-71 | holdout_3 | $48.00 at Page & Quill Books, 11:15 | suspicious | clear | 45 | **no** | Uses 90%+ of the balance |
| FE-72 | holdout_3 | $26.80 at Seventeenth Street Kitchen, 12:50 | normal | clear | 15 | yes |  |
| FE-73 | holdout_3 | $100.00 at Triangle Stems, 23:55 | suspicious | hold | 92 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance, Outside business hours, Above the category's typical range |
| FE-74 | holdout_3 | $55.00 at K Street Cuts, 12:00 | suspicious | clear | 20 | **no** |  |
| FE-75 | holdout_3 | $40.00 at Press Room Deli, 13:05 | normal | hold | 74 | **no** | Round amount ($20+, whole $10s), 3x or more the usual amount, 5+ visits here in 7 days |
| FE-76 | holdout_3 | $20.00 at Lafayette Cycle Studio, 07:00 | normal | clear | 25 | yes | Round amount ($20+, whole $10s) |
| FE-77 | holdout_3 | $120.00 at Connecticut Noodle Bar, 12:30 | suspicious | hold | 78 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, New merchant (0 visits in 30 days), Above the category's typical range |
| FE-78 | holdout_3 | $50.00 at Farragut Grind, 05:50 | suspicious | hold | 95 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance, New merchant (0 visits in 30 days), Outside business hours, Above the category's typical range |
| FE-79 | holdout_3 | $15.99 at Page & Quill Books, 16:40 | normal | clear | 5 | yes |  |
| FE-80 | holdout_3 | $41.20 at Seventeenth Street Kitchen, 20:40 | suspicious | clear | 25 | **no** |  |
| FE-81 | holdout_3 | $68.00 at Triangle Stems, 11:20 | suspicious | hold | 72 | yes | Uses 90%+ of the balance, New merchant (0 visits in 30 days) |
| FE-82 | holdout_3 | $34.00 at K Street Cuts, 19:20 | normal | clear | 15 | yes |  |
| FE-83 | holdout_3 | $160.00 at Press Room Deli, 01:15 | suspicious | hold | 96 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance, New merchant (0 visits in 30 days), Outside business hours, Above the category's typical range |
| FE-84 | holdout_3 | $45.00 at Lafayette Cycle Studio, 06:30 | normal | clear | 35 | yes | Outside business hours, Above the category's typical range |
| FE-85 | holdout_3 | $18.30 at Connecticut Noodle Bar, 13:15 | normal | clear | 12 | yes |  |
| FE-86 | holdout_3 | $30.00 at Farragut Grind, 15:30 | suspicious | clear | 62 | **no** | Round amount ($20+, whole $10s), 3x or more the usual amount, New merchant (0 visits in 30 days), Above the category's typical range |
| FE-87 | holdout_3 | $210.00 at Page & Quill Books, 20:30 | suspicious | hold | 95 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance, New merchant (0 visits in 30 days), Above the category's typical range |
| FE-88 | holdout_3 | $23.40 at Seventeenth Street Kitchen, 13:00 | normal | clear | 15 | yes |  |
| FE-89 | holdout_3 | $90.00 at K Street Cuts, 17:00 | suspicious | clear | 35 | **no** | Round amount ($20+, whole $10s), 3x or more the usual amount |
| FE-90 | holdout_3 | $80.00 at Lafayette Cycle Studio, 11:00 | suspicious | hold | 88 | yes | Round amount ($20+, whole $10s), 3x or more the usual amount, Uses 90%+ of the balance, New merchant (0 visits in 30 days), Above the category's typical range |

## Run history

Every run is kept. A new prompt version means the prompt file changed between runs.

Each cell: agreement (agreed/cases) and how many of the suspicious cases the AI caught.

| Run | Model | Prompt version | Dev | Holdout | Holdout 2 | Holdout 3 | Details | Note |
|---|---|---|---|---|---|---|---|---|
| 2026-10-11 21:44 | `claude-opus-5-5` | `e5bcf207` | 85% (17/20), caught 6 of 7 | 80% (8/10), caught 3 of 4 | n/a | n/a | [runs/2026-10-11-claude-opus-5-5-prompt-e5bcf207.md](runs/2026-10-11-claude-opus-5-5-prompt-e5bcf207.md) | First run, with the original prompt. Its holdout cases (FE-21 to FE-30 group) were unseen at the time. |
| 2026-10-11 21:49 | `claude-opus-5-5` | `8d5e1e70` | 90% (18/20), caught 7 of 7 | 90% (9/10), caught 4 of 4 | n/a | n/a | [runs/2026-10-11-claude-opus-5-5-prompt-8d5e1e70.md](runs/2026-10-11-claude-opus-5-5-prompt-8d5e1e70.md) | Prompt change 1 (hold full-balance redemptions at a merchant not visited in 30 days) was based on dev case FE-18 only, but it was made after the first run's holdout results had been seen, so this run's holdout score is not fully independent. |
| 2026-10-11 22:03 | `claude-opus-5-5` | `8d5e1e70` | 90% (18/20), caught 7 of 7 | 90% (9/10), caught 4 of 4 | 80% (8/10), caught 4 of 4 | n/a | [runs/2026-10-11-claude-opus-5-5-prompt-8d5e1e70-cases-3b40d7c8.md](runs/2026-10-11-claude-opus-5-5-prompt-8d5e1e70-cases-3b40d7c8.md) | First run including holdout_2 (FE-31 to FE-40): hand-labeled after prompt 8d5e1e70 was written and never seen while changing it. Same prompt as run 2. The holdout (FE-21 to FE-30 group) score is still not fully independent. |
| 2026-10-14 16:01 | `claude-opus-5-5` | `8d5e1e70` | 90% (18/20), caught 7 of 7 | 90% (9/10), caught 4 of 4 | 80% (8/10), caught 4 of 4 | 78% (39/50), caught 17 of 26 | [runs/2026-10-14-claude-opus-5-5-prompt-8d5e1e70-cases-33b70229.md](runs/2026-10-14-claude-opus-5-5-prompt-8d5e1e70-cases-33b70229.md) | Prompt unchanged (8d5e1e70). Adds 50 holdout_3 cases, labeled by hand and locked at commit 5f594c1 before the AI saw them; 26 are labeled suspicious. |

Generated by `php artisan fraud:eval`. Method: see [TRANSPARENCY.md](../../TRANSPARENCY.md#how-we-measure-it).
