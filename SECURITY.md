# Security

Local Goods is a student prototype built for a university contest. It runs
in Stripe **test mode**: no real money moves, and all people, businesses and
transactions are fictional.

## Reporting a problem

Email **butlerf@cua.edu** with what you found and how to reproduce it, or
use the **Report a vulnerability** button on the repository's Security tab
(GitHub private vulnerability reporting). Please don't open a public GitHub issue for a vulnerability. I'll reply
within about **5 business days**. If you give me a reasonable time to fix it
before sharing it, I'll credit you if you'd like.

Please never include real personal data in a report, and don't access or
change anyone else's data while testing.

## In scope

- The live site at https://uselocalgoods.com
- The code in this repository

## Out of scope

- Stripe, AWS, CloudFront and other services we use (report those to them)
- Denial-of-service or volume testing
- Social engineering, phishing or physical attacks
- Pressing "Reset demo" or other demo-only buttons: they only touch fictional data

## Repository protections

Turned on in the GitHub repository settings:

- Private vulnerability reporting (the **Report a vulnerability** button).
- Dependency graph and Dependabot alerts. Dependabot security updates are
  off until after the contest freeze.
- Secret scanning with push protection.
- CodeQL default setup. CodeQL doesn't cover PHP, so the PHP code is covered
  by our tests and `composer audit` in CI. Its first scan (2026-11-11) found 5
  alerts: 4 "workflow does not contain permissions" warnings in the CI
  workflow and 1 HTML-insertion issue in `docs/brand/directions.html`, an old
  design mockup that the site doesn't serve (the web root is `public/`; the
  file is in the deploy zip as a document but no route reaches it). All 5 were
  fixed the same day in commit `1635c34`: the workflow now asks for read-only
  access to the repository, and the mockup builds its button with DOM calls
  instead of HTML strings. No app code changed. GitHub closes the alerts
  after it re-scans.
- A ruleset that blocks force pushes and deletion on `main`.

## No bug bounty

This is an unfunded prototype, so there is no bounty or reward. Thank-yous
only.

## What's known not to be done

No independent security review or penetration test yet, and no two-factor
sign-in. See https://uselocalgoods.com/security for the plain-English list.
