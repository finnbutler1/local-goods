# Redemption Fraud Detection eval results

> **Note on this run:** First run including holdout_2 (FE-31 to FE-40): hand-labeled after prompt 8d5e1e70 was written and never seen while changing it. Same prompt as run 2. The holdout (FE-21 to FE-30 group) score is still not fully independent.

| | |
|---|---|
| Run | October 11, 2026 10:03 PM GMT-0400 |
| Model | `claude-opus-5-5` |
| Prompt version | `8d5e1e70` (first 8 characters of the SHA-256 of [`redemption-fraud-detection.md`](../../../resources/prompts/redemption-fraud-detection.md)) |
| Cases | 40, version `3b40d7c8` hand-labeled synthetic redemptions ([`data/fraud_eval_cases.csv`](../../../data/fraud_eval_cases.csv)) |

## Agreement with the human labels

| Split | Agreement |
|---|---|
| Holdout 2 (labeled after the last prompt change, never seen while changing it) | 80% (8/10) |
| **Holdout** (not used to change the prompt) | **90% (9/10)** |
| Dev (may be used to improve the prompt) | 90% (18/20) |
| All cases | 87.5% (35/40) |

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

## Disagreements (5)

| Case | Split | Redemption | Human | Human notes | AI | AI explanation |
|---|---|---|---|---|---|---|
| FE-08 | dev | $40.00 at Farragut Grind, 13:00 | normal | regular customer | hold (85) | This is a round $40.00 charge at a cafe, more than six times this employee's usual $6.40 spend and well above the normal $3–$15 cafe ticket. The employee has also made 9 visits to this same cafe in the past 7 days (22 in 30 days). |
| FE-15 | dev | $100.00 at Connecticut Noodle Bar, 14:00 | normal | treating oneself | hold (88) | This $100.00 redemption is a round amount, about 7 times the employee's usual $13.87 spend, and well above the typical $10–$45 restaurant ticket. It also follows 6 visits to the same merchant in the past 7 days (14 in 30 days). |
| FE-21 | holdout | $60.00 at Press Room Deli, 12:30 | normal | previous customer making a bigger purchase | hold (82) | This $60.00 restaurant redemption is a round amount, about 4.3 times the employee's usual $13.87 spend, and above the normal $10–$45 restaurant ticket. The employee has also made 5 prior redemptions at this same merchant in the last 7 days (11 in 30 days), so several signals point the same way. |
| FE-34 | holdout_2 | $50.00 at Press Room Deli, 12:10 | normal | large amount but has visited here previously and the account is not drained | hold (82) | This is a round $50.00 charge, about 4 times the employee's usual $12.00 spend and above the normal $10–$45 restaurant ticket. It also follows 7 prior visits to the same restaurant in the last 7 days (15 in 30 days), so several signals point to possible collusion. |
| FE-38 | holdout_2 | $20.00 at Farragut Grind, 09:15 | normal | recurring customer at a reasonable time in the morning | hold (72) | Several signals point the same way: an exactly $20.00 charge at a cafe is above the normal $3–$15 ticket and about 3.5 times this employee's usual $5.80 spend, and this is their 4th visit to the same cafe in 7 days (10th in 30). On its own this would be a small amount, but the round, inflated figure combined with frequent repeat visits warrants a review. |

## Every case

| Case | Split | Redemption | Human | AI | Score | Agree |
|---|---|---|---|---|---|---|
| FE-01 | dev | $80.00 at Triangle Stems, 10:30 | suspicious | hold | 78 | yes |
| FE-02 | dev | $14.20 at Connecticut Noodle Bar, 19:30 | normal | clear | 5 | yes |
| FE-03 | dev | $36.50 at Triangle Stems, 18:05 | normal | clear | 25 | yes |
| FE-04 | holdout | $9.60 at Press Room Deli, 11:45 | normal | clear | 5 | yes |
| FE-05 | dev | $22.80 at Connecticut Noodle Bar, 22:20 | normal | clear | 45 | yes |
| FE-06 | dev | $120.00 at Seventeenth Street Kitchen, 02:15 | suspicious | hold | 97 | yes |
| FE-07 | dev | $17.40 at Connecticut Noodle Bar, 12:40 | normal | clear | 8 | yes |
| FE-08 | dev | $40.00 at Farragut Grind, 13:00 | normal | hold | 85 | **no** |
| FE-09 | holdout | $13.50 at Press Room Deli, 06:45 | normal | clear | 35 | yes |
| FE-10 | dev | $150.00 at K Street Cuts, 23:15 | suspicious | hold | 95 | yes |
| FE-11 | holdout | $21.35 at Seventeenth Street Kitchen, 13:10 | normal | clear | 12 | yes |
| FE-12 | dev | $80.00 at Farragut Grind, 06:10 | suspicious | hold | 95 | yes |
| FE-13 | dev | $11.85 at Press Room Deli, 12:05 | normal | clear | 5 | yes |
| FE-14 | dev | $24.99 at Page & Quill Books, 17:30 | normal | clear | 15 | yes |
| FE-15 | dev | $100.00 at Connecticut Noodle Bar, 14:00 | normal | hold | 88 | **no** |
| FE-16 | holdout | $4.75 at Farragut Grind, 15:20 | normal | clear | 5 | yes |
| FE-17 | dev | $200.00 at Lafayette Cycle Studio, 05:30 | suspicious | hold | 92 | yes |
| FE-18 | dev | $64.20 at Page & Quill Books, 12:50 | suspicious | hold | 75 | yes |
| FE-19 | holdout | $270.00 at Page & Quill Books, 22:45 | suspicious | hold | 97 | yes |
| FE-20 | dev | $28.00 at Lafayette Cycle Studio, 07:10 | normal | clear | 15 | yes |
| FE-21 | holdout | $60.00 at Press Room Deli, 12:30 | normal | hold | 82 | **no** |
| FE-22 | holdout | $150.00 at Triangle Stems, 03:05 | suspicious | hold | 95 | yes |
| FE-23 | holdout | $85.00 at K Street Cuts, 18:30 | suspicious | hold | 78 | yes |
| FE-24 | dev | $42.00 at K Street Cuts, 11:00 | normal | clear | 25 | yes |
| FE-25 | holdout | $150.00 at Triangle Stems, 23:40 | suspicious | hold | 95 | yes |
| FE-26 | dev | $90.00 at Seventeenth Street Kitchen, 23:50 | suspicious | hold | 92 | yes |
| FE-27 | dev | $30.00 at Farragut Grind, 08:20 | normal | clear | 45 | yes |
| FE-28 | dev | $40.00 at Lafayette Cycle Studio, 06:00 | normal | clear | 38 | yes |
| FE-29 | dev | $6.25 at Farragut Grind, 08:35 | normal | clear | 4 | yes |
| FE-30 | holdout | $48.60 at Seventeenth Street Kitchen, 20:15 | normal | clear | 45 | yes |
| FE-31 | holdout_2 | $18.75 at Page & Quill Books, 12:20 | normal | clear | 10 | yes |
| FE-32 | holdout_2 | $8.40 at Farragut Grind, 07:50 | normal | clear | 8 | yes |
| FE-33 | holdout_2 | $140.00 at Connecticut Noodle Bar, 21:45 | suspicious | hold | 95 | yes |
| FE-34 | holdout_2 | $50.00 at Press Room Deli, 12:10 | normal | hold | 82 | **no** |
| FE-35 | holdout_2 | $200.00 at K Street Cuts, 01:30 | suspicious | hold | 92 | yes |
| FE-36 | holdout_2 | $120.00 at Lafayette Cycle Studio, 12:00 | suspicious | hold | 78 | yes |
| FE-37 | holdout_2 | $38.90 at Seventeenth Street Kitchen, 21:50 | normal | clear | 30 | yes |
| FE-38 | holdout_2 | $20.00 at Farragut Grind, 09:15 | normal | hold | 72 | **no** |
| FE-39 | holdout_2 | $32.00 at Lafayette Cycle Studio, 18:15 | normal | clear | 18 | yes |
| FE-40 | holdout_2 | $72.50 at Triangle Stems, 17:40 | suspicious | hold | 75 | yes |

Generated by `php artisan fraud:eval`. Method: see [TRANSPARENCY.md](../../../TRANSPARENCY.md#how-we-measure-it).
