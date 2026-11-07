# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Employer HR and benefits managers at major Golden Triangle employers (banks, law firms, agencies) come first. They are the buyer, deciding whether to trust and purchase, so the interface should feel like a trustworthy benefits product.

Evaluators (contest judges, Golden Triangle BID staff, business owners) form their first impression from the landing page, the public pages and the `/judge` walkthrough. Those pages must read as credible, calm and professional within 10 seconds.

Employees spending credits on a phone come next, then BID admins and merchants.

## Product Purpose

Employers inside a Business Improvement District buy credits. Their employees can spend those credits only at independent merchants in that BID. Stripe Connect (test mode) holds and moves the money; Local Goods keeps the entitlement ledger and enforces the rules.

Scope is one BID (Golden Triangle, Washington DC) and one employer. The mission is "You work here. Spend here."

## Positioning

A closed loop with human-checked AI and money you can audit. Credits can only be spent at approved independent merchants in the district. AI matches merchants for eligibility and flags suspicious redemptions, but held money never moves until a person decides. Every cent is on an append-only ledger that is shown to match the real Stripe balances.

The local-spend mission is the why; this is the proof.

## Operating Context

- All demo people and transactions are fictional.
- Contest judging is the week of Nov 23, 2026 (CUA Busch School AI Vibe Coding Contest).
- Live at https://uselocalgoods.com, deployed by hand to Elastic Beanstalk behind CloudFront.

## Capabilities and Constraints

- Independence rule (verbatim in CLAUDE.md): never paraphrase it in copy or prompts.
- The fraud check fails closed. Any AI error means hold.
- No card program in the MVP.
- Judges are read-only. Personal contacts see only the voting pages.
- No fee, revenue or interchange figures on public pages. The "3x" line appears only with its outside-study label and source. Never imply the BID endorses or partners with Local Goods.
- **Content is locked.** Keep every page, tab, menu item, section, label and piece of information that exists today. Design changes are visual only: layout, spacing, typography, color, hierarchy and alignment. Never remove, merge, hide or rename content or navigation without asking Finn first.

## Brand Commitments

Binding; refine only. The Transit lines brand (ADR 0025, `docs/brand/`) stays: the Overpass and Atkinson Hyperlegible Next fonts, the route-line motif, "Reroute your lunch." and the current overall layout.

The goal of the design pass is to make it look less AI-generated, not to redesign it.

- Allowed: tighter spacing, alignment and type sizes; cutting generic decoration (gradients, glows, emoji, too many cards or badges, rounded-everything); making the route motif feel more deliberate and less decorative.
- Not allowed: new fonts, a new color world, a new tagline, or removing content.
- Layout rule: center the main content column, the hero and page headings, but keep body text, lists, tables and forms left-aligned.

## Evidence on Hand

- Redemption Fraud Detection holdout (`holdout_3`, 50 cases): caught 17 of 26 suspicious; 17 of 19 holds were suspicious.
- Merchant Eligibility Matching eval and blind adjudication results, at `/eval` and `/eval/merchants`.
- Decision records in `docs/adr/`, `TRANSPARENCY.md`, `docs/accessibility.md`.
- There are no real testimonials, customers or pricing. Do not fabricate any.

## Product Principles

1. Trust before polish: it should look like a calm, credible benefits product.
2. A person decides; the AI only recommends and explains.
3. Show the numbers and how they were counted.
4. Label demo data and projections honestly.
5. Restraint over decoration.

## Accessibility & Inclusion

Accessibility and ethical AI (explain AI decisions, let a human override, show confidence) are contest bonus criteria. Keep labels, contrast (`ColorContrast::PAIRS`, light and dark) and keyboard use working, with 44px tap targets on phones. Not yet tried with a screen reader or on a real phone.
