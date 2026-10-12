# 0004. Elastic Beanstalk instead of Amplify

**Status:** Accepted, implemented

## Context

The app needed a live URL on AWS. AWS Amplify Hosting is built for static
sites and server-rendered JavaScript frameworks such as Next.js. Local Goods is
a Laravel app: a PHP server that renders pages, writes to a database and calls
the Claude API with a secret key on the server.

## Decision

Host on **AWS Elastic Beanstalk** with the PHP platform, as a single instance.

- Config lives in [`.ebextensions/`](../../.ebextensions) and
  [`.platform/`](../../.platform).
- Elastic Beanstalk runs `composer install` on deploy.
- A predeploy hook runs migrations and seeds the demo only into an empty database ([ADR 0006](0006-amazon-rds-mysql-in-production.md)).
- Deploys are a zip built by `scripts/build-deploy-zip.sh` and uploaded in the
  console.

## Consequences

- Laravel runs as designed, with no rewrite into a JavaScript framework.
- Environment variables (including `ANTHROPIC_API_KEY`) are set in the
  Elastic Beanstalk console and never committed.
- There is no auto-deploy from GitHub yet. CI tests every push, but deploying
  is a manual upload.
- A single instance is fine for a demo but is not highly available.
- The live URL is HTTP for now. HTTPS needs a custom domain and certificate
  (planned).
