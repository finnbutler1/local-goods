# Judges: the five-minute path

On [uselocalgoods.com](https://uselocalgoods.com), press **Look around (read-only)**. No account or password. Then follow the 8 stops of the guided tour on `/judge`. Everything is Stripe test mode with fictional people and businesses.

| Stop | What to notice |
|---|---|
| 1. Employee wallet | A balance funded by the employer, spendable only at independent merchants in the district. |
| 2. See tax at checkout | DC sales tax shown per item before paying. |
| 3. Fraud Review | Each AI score with its anonymous signals, and the separate decision a person made. |
| 4. AI Eval | Results on cases the AI never saw, with every disagreement. |
| 5. Merchant Eligibility | The AI only suggests; a BID admin decides. No web search. |
| 6. Employer dashboard | What the HR buyer sees, with how each number is counted. |
| 7. Merchant dashboard | Only cleared sales are owed; held ones wait for review. |
| 8. Corrections | Refunds need a reason, and the books still match. |

## Try it yourself

If **Try it yourself** shows on the home page or on your judge Home, press it for a private copy of the demo where money really moves (it appears only while switched on). You get a role bar to switch between Employer (HR), Employee, Merchant, BID admin and Local Goods reviewer with one click, no passwords, and a "Try this" checklist:

1. Buy credits as the employer (simulated: no card is charged and Stripe is not contacted).
2. Give some to Jordan Rivera.
3. Pay at Farragut Grind as the employee.
4. See the sale and its simulated payout as the merchant.
5. See the district's impact as the BID admin.
6. Try a suspicious payment, and 7. decide it in Fraud Review as the Local Goods reviewer.

The fraud check is the real one (up to 10 AI checks per sandbox; after that payments are held for a person). Nothing you do there touches the live demo or Stripe, and your copy is deleted after 24 hours or when you press Exit. Your read-only login is unchanged.

## Where the evidence lives

- AI results: [/eval](https://uselocalgoods.com/eval), [/eval/merchants](https://uselocalgoods.com/eval/merchants), [README: How the AI was measured](README.md#how-the-ai-was-measured)
- Money: [Where the money is](https://uselocalgoods.com/where-the-money-is)
- Security: [/security](https://uselocalgoods.com/security), [docs/security-verification.md](docs/security-verification.md)
- [What is deployed right now](https://uselocalgoods.com/version): the commit and build time of the running copy
- Roadmap concepts: [/coming-next](https://uselocalgoods.com/coming-next)
- Method and limits: [TRANSPARENCY.md](TRANSPARENCY.md); decisions: [docs/adr/](docs/adr/)

## What is not claimed

- Real money. This is a prototype in Stripe test mode.
- Any endorsement or partnership by the Business Improvement District or any delivery service.
- An independent penetration test.
- That the fraud check catches planted collusion rings. It does not.
- Web search in Merchant Eligibility Matching.
- Handling of card disputes and returned payments.
- A merchant fee charged in the product (it is set to 0 during the pilot), or unspent credits counted as revenue.
- Real BID program fees. The BID annual program fee is built (ADR 0044) and charges only in Stripe test mode, with no real money, when `BID_PROGRAM_FEE_ENABLED` is switched on; it is off by default. There is no free pilot year in the model: every district pays from its program start date.

## Still to come

The 2–3 minute screen recording and the judge link go at the top of the README by Nov 20. Until then the README says "coming Nov 20".
