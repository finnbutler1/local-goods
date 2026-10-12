# Redemption Fraud Detection eval results

| | |
|---|---|
| Run | October 11, 2026 9:44 PM GMT-0400 |
| Model | `claude-opus-5-5` |
| Prompt version | `e5bcf207` (first 8 characters of the SHA-256 of [`redemption-fraud-detection.md`](../../resources/prompts/redemption-fraud-detection.md)) |
| Cases | 30 hand-labeled synthetic redemptions ([`data/fraud_eval_cases.csv`](../../data/fraud_eval_cases.csv)) |

## Agreement with the human labels

| Split | Agreement |
|---|---|
| **Holdout** (never used to change the prompt) | **80% (8/10)** |
| Dev (may be used to improve the prompt) | 85% (17/20) |
| All cases | 83.3% (25/30) |

The AI's `hold` counts as *suspicious* and `clear` as *normal*.

## Confusion matrices

Rows are the human label, columns are the AI's call.

**Dev**

| Human ↓ / AI → | normal (clear) | suspicious (hold) |
|---|---|---|
| normal | 11 | 2 (false alarm) |
| suspicious | 1 (missed) | 6 |

**Holdout**

| Human ↓ / AI → | normal (clear) | suspicious (hold) |
|---|---|---|
| normal | 5 | 1 (false alarm) |
| suspicious | 1 (missed) | 3 |

## Disagreements (5)

| Case | Split | Redemption | Human | Human notes | AI | AI explanation |
|---|---|---|---|---|---|---|
| FE-08 | dev | $40.00 at Farragut Grind, 13:00 | normal | regular customer | hold (85) | This is a round $40.00 charge at a cafe where a typical ticket is $3–$15, about 6 times this employee's usual $6.40 spend. It also follows 9 visits to the same merchant in the past 7 days (22 in 30 days). |
| FE-15 | dev | $100.00 at Connecticut Noodle Bar, 14:00 | normal | treating oneself | hold (88) | This is an exactly $100.00 restaurant charge, about 7 times the employee's usual $13.87 and well above the normal $10–$45 restaurant ticket. It also follows 6 prior visits to the same merchant in the last 7 days (14 in 30 days). |
| FE-18 | dev | $64.20 at Page & Quill Books, 12:50 | suspicious | drains entire account while never coming previously | clear (35) | This $64.20 retail purchase uses the full remaining balance and is about 3.6 times the employee's $18.00 median, but the median comes from only 2 past redemptions. The amount is not round, is within the normal $10–$80 range for retail, it is the first visit to this merchant, and it happened at midday. |
| FE-21 | holdout | $60.00 at Press Room Deli, 12:30 | normal | previous customer making a bigger purchase | hold (82) | This is a round $60.00 charge, about 4.3 times the employee's usual $13.87 and above the normal $10–$45 restaurant ticket. It is also the employee's sixth visit to this restaurant in 7 days (11 prior visits in 30 days), so several warning signs point the same way, even though it happened at lunchtime and did not drain the $210.00 balance. |
| FE-23 | holdout | $85.00 at K Street Cuts, 18:30 | suspicious | drains account without ever being there | clear (45) | This $85.00 salon redemption uses nearly all of the $88.00 balance and is about 6 times the employee's usual $13.87 spend, but it falls within the normal $25–$90 range for a salon. It is also not a round number, happened during business hours at 6 PM, and is the first visit to this merchant in 30 days, which fits a legitimate salon appointment. |

## Every case

| Case | Split | Redemption | Human | AI | Score | Agree |
|---|---|---|---|---|---|---|
| FE-01 | dev | $80.00 at Triangle Stems, 10:30 | suspicious | hold | 74 | yes |
| FE-02 | dev | $14.20 at Connecticut Noodle Bar, 19:30 | normal | clear | 4 | yes |
| FE-03 | dev | $36.50 at Triangle Stems, 18:05 | normal | clear | 22 | yes |
| FE-04 | holdout | $9.60 at Press Room Deli, 11:45 | normal | clear | 5 | yes |
| FE-05 | dev | $22.80 at Connecticut Noodle Bar, 22:20 | normal | clear | 40 | yes |
| FE-06 | dev | $120.00 at Seventeenth Street Kitchen, 02:15 | suspicious | hold | 97 | yes |
| FE-07 | dev | $17.40 at Connecticut Noodle Bar, 12:40 | normal | clear | 5 | yes |
| FE-08 | dev | $40.00 at Farragut Grind, 13:00 | normal | hold | 85 | **no** |
| FE-09 | holdout | $13.50 at Press Room Deli, 06:45 | normal | clear | 30 | yes |
| FE-10 | dev | $150.00 at K Street Cuts, 23:15 | suspicious | hold | 92 | yes |
| FE-11 | holdout | $21.35 at Seventeenth Street Kitchen, 13:10 | normal | clear | 12 | yes |
| FE-12 | dev | $80.00 at Farragut Grind, 06:10 | suspicious | hold | 92 | yes |
| FE-13 | dev | $11.85 at Press Room Deli, 12:05 | normal | clear | 5 | yes |
| FE-14 | dev | $24.99 at Page & Quill Books, 17:30 | normal | clear | 12 | yes |
| FE-15 | dev | $100.00 at Connecticut Noodle Bar, 14:00 | normal | hold | 88 | **no** |
| FE-16 | holdout | $4.75 at Farragut Grind, 15:20 | normal | clear | 4 | yes |
| FE-17 | dev | $200.00 at Lafayette Cycle Studio, 05:30 | suspicious | hold | 92 | yes |
| FE-18 | dev | $64.20 at Page & Quill Books, 12:50 | suspicious | clear | 35 | **no** |
| FE-19 | holdout | $270.00 at Page & Quill Books, 22:45 | suspicious | hold | 92 | yes |
| FE-20 | dev | $28.00 at Lafayette Cycle Studio, 07:10 | normal | clear | 15 | yes |
| FE-21 | holdout | $60.00 at Press Room Deli, 12:30 | normal | hold | 82 | **no** |
| FE-22 | holdout | $150.00 at Triangle Stems, 03:05 | suspicious | hold | 92 | yes |
| FE-23 | holdout | $85.00 at K Street Cuts, 18:30 | suspicious | clear | 45 | **no** |
| FE-24 | dev | $42.00 at K Street Cuts, 11:00 | normal | clear | 22 | yes |
| FE-25 | holdout | $150.00 at Triangle Stems, 23:40 | suspicious | hold | 95 | yes |
| FE-26 | dev | $90.00 at Seventeenth Street Kitchen, 23:50 | suspicious | hold | 90 | yes |
| FE-27 | dev | $30.00 at Farragut Grind, 08:20 | normal | clear | 45 | yes |
| FE-28 | dev | $40.00 at Lafayette Cycle Studio, 06:00 | normal | clear | 35 | yes |
| FE-29 | dev | $6.25 at Farragut Grind, 08:35 | normal | clear | 4 | yes |
| FE-30 | holdout | $48.60 at Seventeenth Street Kitchen, 20:15 | normal | clear | 35 | yes |

## Run history

Every run is kept. A new prompt version means the prompt file changed between runs.

| Run | Model | Prompt version | Dev | Holdout | All | Details |
|---|---|---|---|---|---|---|
| 2026-10-11 21:44 | `claude-opus-5-5` | `e5bcf207` | 85% | 80% | 83.3% | [runs/2026-10-11-claude-opus-5-5-prompt-e5bcf207.md](runs/2026-10-11-claude-opus-5-5-prompt-e5bcf207.md) |

Generated by `php artisan fraud:eval`. Method: see [TRANSPARENCY.md](../../TRANSPARENCY.md#how-we-measure-it).
