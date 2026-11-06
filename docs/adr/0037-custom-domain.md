# 0037. The site moves to uselocalgoods.com; CloudFront redirects every other address

**Status:** Accepted. Code, tests and docs done; the switch itself is made in
the AWS and Stripe consoles (checklist below). No migration, money, ledger,
fraud prompt, AI input, eval data or lock change.

## Context

`uselocalgoods.com` and `www.uselocalgoods.com` now point at the CloudFront
distribution, with an ACM certificate. The old `d4ghrpm72uczx.cloudfront.net`
address is in judges' bookmarks, magic links already sent, open Stripe
Checkout sessions and both Stripe webhook destinations, so it must keep
working.

CloudFront doesn't pass the visitor's host on to Beanstalk (ADR 0009), so
Laravel can't tell which address someone used. In production every link,
form action and redirect is built from APP_URL. The session cookie belongs to
the exact host that set it (`SESSION_DOMAIN` unset). So a page served on one
address posts its forms to APP_URL on another, where the browser has no
session cookie: the form fails with "page expired" and logins don't stick.
Serving two addresses side by side therefore doesn't work, and Laravel can't
fix it.

## Decision

- **One address: `https://uselocalgoods.com`** (no www). APP_URL is set to
  it in Beanstalk.
- **Every other address gets a 301 to it at CloudFront**, by the CloudFront
  Function `infra/cloudfront/canonical-host.js` on the default behaviour's
  Viewer request. Path and query are kept; browsers keep the `#…` part
  through a redirect, so old judge links (`/judge-access#id.token`) and
  application status links still work. `CanonicalHostFunctionTest` runs the
  real file with node.
- **Never redirected, on any address:** `/stripe/webhook` (Stripe doesn't
  follow redirects; the signature is the only check and the host isn't part
  of it) and `/up` (health check).
- **Stripe webhook destinations** are edited in place to the new URL after
  the switch. Editing keeps each destination's signing secret, so no
  Beanstalk change and no gap; the old URL keeps delivering until then.
- **Magic links are path-based, not host-signed.** Only `id.token` is
  checked against the stored hash, so a link made before the switch works
  on both addresses. Never press New link for this.
- **Stripe return URLs** (Checkout, and now onboarding refresh/return too)
  come from `AppUrl::route`, so they follow APP_URL.
- **`config('app.previous_urls')`** lists the old address, so feedback saved
  with an old page link still opens from the inbox.
- **The deploy check refuses an APP_URL that isn't https**
  (`app:check-production-config`), so a typo during the switch stops the
  configuration change before PHP restarts.
- Unchanged: the origin lock and `TrustCloudFront` (the secret header is the
  same for every address), session and cookie settings, the demo activity
  generator (no return URL, runs on the instance) and the deploy hooks.

## Switch-over order

1. Deploy this code (no migrations). Nothing changes for visitors.
2. Create and publish the function in CloudFront, using its Test tab; don't
   associate it yet.
3. At a quiet time: set APP_URL in Beanstalk; as soon as that update
   finishes, associate the function; then open an old judge link.
4. Edit both Stripe webhook destinations' URL.

The window between steps 3's two changes is a few minutes; during it a
visitor on another address may have to log in again.

**Rollback:** remove the function association and set APP_URL back.

## Consequences

- One canonical address for links, cookies and search engines.
- CloudFront Functions are billed per call (about $0.10 per million).
- ADR 0009 and 0018 still describe how CloudFront reaches Beanstalk; only the
  address changed.
