# Judges: the five-minute path

**Start with Try it yourself.** On [uselocalgoods.com](https://uselocalgoods.com), press **Try it yourself** for a private copy of the demo where you move money between the employer, employee, merchant and BID admin, and pay with Stripe test mode (see below). No account or password.

**Or explore the live demo (read-only):** press **Explore the live demo (read-only)**, the link under that button (also on your judge Home as "Or explore the live demo"), then follow the 8 stops of the guided tour on `/judge`. Only the live demo has the AI evaluation, Where the Money Is, the Local Goods admin views and the Feedback button. If Try it yourself isn't available, use Explore the live demo (read-only). Everything is Stripe test mode with fictional people and businesses.

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

Press **Try it yourself** on the home page or at the top of your judge Home (it appears only while switched on). You get a private copy of the demo where money really moves, a role bar to switch between Employer (HR), Employee, Merchant, BID admin and Local Goods reviewer with one click, no passwords, and a "Try this" checklist. A "For judges" box in the sandbox lists what only the live demo has, with **Exit to the live demo**:

1. Buy credits as the employer. Simulated (no card is charged and Stripe is not contacted), unless the sandbox says payments use a separate Stripe test account: then you pay on Stripe's card form (or Stripe's own page). Use card 4242 4242 4242 4242 with any future expiry date (e.g. 12/34), any 3-digit CVC, any ZIP code and any name. No real money, and nothing reaches the live demo; the email is filled in for you.
2. As Employer (HR), in Give credits, choose Jordan Rivera (the employee you'll play) and give some. Credits come from the employer, not the employee.
3. Pay at any participating merchant (Farragut Grind is a good first stop) as the employee, then press Merchant, or "See this as the merchant", to see the sale from the shop's side.
4. See what the merchant is owed (payouts are grouped and paid later; in the sandbox they stay simulated).
5. See the district's impact as the BID admin.
6. Try a suspicious payment, and 7. decide it in Fraud Review as the Local Goods reviewer.

The fraud check is the real one (up to 10 AI checks per sandbox; after that payments are held for a person). Nothing you do there touches the live demo or the live site's Stripe account, and your copy is deleted after 24 hours or when you press Exit. Your read-only login is unchanged.

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
