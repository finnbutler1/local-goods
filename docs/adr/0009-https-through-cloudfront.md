# 0009. HTTPS through CloudFront in front of Beanstalk

**Status:** Accepted, implemented (code); CloudFront set up in the AWS console.

## Context

Production was plain HTTP, so passwords and reviewer invite links travelled
unencrypted (see ADR 0008). The Beanstalk environment is a single instance
with no load balancer, so the quickest free HTTPS is a CloudFront distribution
on its `cloudfront.net` address. CloudFront calls Beanstalk over plain HTTP
and does not pass on the Host header, so Laravel sees every request as
`http://<beanstalk-host>/…` coming from a CloudFront edge server.

## Decision

- **One address in production.** `UseAppUrl` (global middleware, production
  only) forces generated URLs to APP_URL over https *and* rewrites the
  request's own host and scheme to APP_URL. The second part matters because
  "back" redirects (every validation error), the page address saved with
  feedback and similar values come from the request, not the URL generator.
  Production has exactly one real address, so this loses nothing and also
  blocks Host-header tricks.
- **Invite links sign the path only** (`absolute: false`, checked with
  `hasValidRelativeSignature`). The signature still covers the reviewer, the
  token and the expiry; the link stays single use.
- **The visitor's IP is believed only from CloudFront.** CloudFront adds the
  visitor's IP to `X-Forwarded-For`, but anyone can send that header straight
  to Beanstalk. CloudFront also sends a secret `X-Origin-Verify` header
  (`CLOUDFRONT_ORIGIN_SECRET`). `TrustCloudFront` replaces Laravel's
  TrustProxies: with the right secret it trusts only the connecting edge and
  only `X-Forwarded-For`, so the right-most entry (CloudFront's) is the IP and
  anything a visitor typed further left is ignored. Without it, nothing is
  trusted. Trusting every proxy (`*`) was rejected: it would let a direct
  caller pick a new fake IP for every login attempt.
- **Secure session cookie** (`SESSION_SECURE_COOKIE: "true"` in
  `.ebextensions/laravel.config`).

## Consequences

- Invite links sent before this change stop working; "New link" replaces them.
- Logging in on the raw Beanstalk http address no longer works (browsers
  won't keep a Secure cookie over http); it redirects to CloudFront anyway.
- If the origin secret leaks, rotate it in CloudFront and Beanstalk together.
  Restricting Beanstalk's security group to CloudFront's AWS-managed prefix
  list would also stop direct calls entirely.
- `BehindCloudFrontTest` replays requests the way Beanstalk receives them.
- Superseded in part by [0018](0018-only-cloudfront-reaches-beanstalk.md): direct calls to Beanstalk are now refused outright in production.
