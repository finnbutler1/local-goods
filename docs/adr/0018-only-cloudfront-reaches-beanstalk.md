# 0018. Only CloudFront reaches Beanstalk, with a kill switch; unsafe production settings stop a deploy

**Status:** Accepted, implemented. Builds on [0009](0009-https-through-cloudfront.md).

## Context

Since 0009, CloudFront sends a secret `X-Origin-Verify` header to Beanstalk,
but the secret only decided whether to believe `X-Forwarded-For`. Anyone who
found the raw `…elasticbeanstalk.com` address could still use the site over
plain http, around CloudFront. Separately, nothing stopped a deploy with
`APP_DEBUG=true`, which shows code, settings and data on every error page.

## Decision

- **`RequireCloudFrontOrigin`** (first global middleware): in production, a
  request without the right secret (`TrustCloudFront::cameThroughCloudFront`,
  `hash_equals`) gets a plain-text 403. Only `/up`, Beanstalk's health
  check, is let through. The Stripe webhook is covered too: Stripe calls the
  CloudFront address, so it carries the header.
- **Kill switch:** the Beanstalk environment property
  `ORIGIN_VERIFY_ENFORCE` (default true; `config('services.cloudfront.enforce')`).
  Setting it to `false` turns the lock off with a configuration change, no
  redeploy, in case CloudFront stops sending the header.
- **Deploy check:** `app:check-production-config` (`App\Support\ProductionConfig`)
  runs in the predeploy hook (also run on configuration changes) before
  anything else touches the server. It fails when `APP_DEBUG` is true,
  `APP_ENV` isn't production, `SESSION_SECURE_COOKIE` isn't true, or the
  secret is empty while the lock is on. The deploy stops and the running
  site is unchanged. Messages name the setting, never its value.
- **Last line of defence:** if production boots with debug on anyway, it
  turns debug off and logs a critical message. Throwing would itself show a
  debug page.
- With the lock on and an empty secret, everyone is refused (fail closed);
  the deploy check is what stops that from going live.

## Consequences

- The raw Beanstalk address returns 403. Check this after each deploy.
- If the site starts returning 403 through CloudFront, the header is
  missing or different: set `ORIGIN_VERIFY_ENFORCE=false`, then fix the
  CloudFront origin's custom header and set it back to `true`.
- Rotating the secret means changing CloudFront's header and Beanstalk's
  `CLOUDFRONT_ORIGIN_SECRET` together; in between, set the kill switch off.
- Restricting the security group to CloudFront's managed prefix list would
  close the address at the network level too; not done.
- `BehindCloudFrontTest`, `ProductionConfigTest` and `DeployHookTest` cover it.
