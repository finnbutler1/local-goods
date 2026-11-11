# Security

Local Goods is a student prototype built for a university contest. It runs
in Stripe **test mode**: no real money moves, and all people, businesses and
transactions are fictional.

## Reporting a problem

Email **butlerf@cua.edu** with what you found and how to reproduce it.
Please don't open a public GitHub issue for a vulnerability. I'll reply
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

## No bug bounty

This is an unfunded prototype, so there is no bounty or reward. Thank-yous
only.

## What's known not to be done

No independent security review or penetration test yet, and no two-factor
sign-in. See https://uselocalgoods.com/security for the plain-English list.
