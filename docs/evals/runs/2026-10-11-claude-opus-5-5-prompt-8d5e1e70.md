# Redemption Fraud Detection eval results

> **Note on this run:** Prompt change 1 (hold full-balance redemptions at a merchant not visited in 30 days) was based on dev case FE-18 only, but it was made after the first run's holdout results had been seen, so this run's holdout score is not fully independent.

| | |
|---|---|
| Run | October 11, 2026 9:49 PM GMT-0400 |
| Model | `claude-opus-5-5` |
| Prompt version | `8d5e1e70` (first 8 characters of the SHA-256 of [`redemption-fraud-detection.md`](../../../resources/prompts/redemption-fraud-detection.md)) |
| Cases | 30 hand-labeled synthetic redemptions ([`data/fraud_eval_cases.csv`](../../../data/fraud_eval_cases.csv)) |

## Agreement with the human labels

| Split | Agreement |
|---|---|
| **Holdout** (not used to change the prompt) | **90% (9/10)** |
| Dev (may be used to improve the prompt) | 90% (18/20) |
| All cases | 90% (27/30) |

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

## Disagreements (3)

| Case | Split | Redemption | Human | Human notes | AI | AI explanation |
|---|---|---|---|---|---|---|
| FE-08 | dev | $40.00 at Farragut Grind, 13:00 | normal | regular customer | hold (85) | This is a round $40.00 charge at a cafe, more than six times this employee's usual $6.40 spend and well above the normal $3–$15 cafe ticket. The employee has also made 9 visits to this same cafe in the past 7 days (22 in 30 days). |
| FE-15 | dev | $100.00 at Connecticut Noodle Bar, 14:00 | normal | treating oneself | hold (88) | This $100.00 redemption is a round amount, about 7 times the employee's usual $13.87 spend, and well above the typical $10–$45 restaurant ticket. It also follows 6 visits to the same merchant in the past 7 days (14 in 30 days). |
| FE-21 | holdout | $60.00 at Press Room Deli, 12:30 | normal | previous customer making a bigger purchase | hold (82) | This $60.00 restaurant redemption is a round amount, about 4.3 times the employee's usual $13.87 spend, and above the normal $10–$45 restaurant ticket. The employee has also made 5 prior redemptions at this same merchant in the last 7 days (11 in 30 days), so several signals point the same way. |

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

Generated by `php artisan fraud:eval`. Method: see [TRANSPARENCY.md](../../../TRANSPARENCY.md#how-we-measure-it).
