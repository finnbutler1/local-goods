#!/usr/bin/env bash
# Read-only security probes against the live site. Sends only plain requests
# with no credentials and no body, and writes nothing to disk.
#
#   scripts/security-probes.sh [raw-beanstalk-host]
#
# The optional argument is the raw Elastic Beanstalk address (for example
# something.elasticbeanstalk.com); it should answer 403 because only CloudFront
# may reach it. The check uses http:// because that environment has no HTTPS
# listener (CloudFront terminates TLS). Without it that check is skipped. Exits 1 if any check fails.
# Explained in docs/security-verification.md.

set -u

SITE="https://uselocalgoods.com"
OLD_HOST="https://d4ghrpm72uczx.cloudfront.net"
BEANSTALK="${1:-}"
fail=0

pass() { printf 'PASS  %s\n' "$1"; }
bad()  { printf 'FAIL  %s\n' "$1"; fail=1; }

status() { # method url
  # curl prints 000 itself when it cannot connect, so don't add a second one.
  local code
  code=$(curl -sS -o /dev/null --max-time 10 -X "$1" -w '%{http_code}' "$2" 2>/dev/null) || code="000"
  printf '%s' "${code:-000}" | tail -c 3
}

headers=$(curl -sS -D - -o /dev/null --max-time 10 "$SITE/" 2>/dev/null | tr -d '\r')
for h in X-Content-Type-Options Referrer-Policy X-Frame-Options Permissions-Policy \
         Content-Security-Policy-Report-Only Strict-Transport-Security; do
  if printf '%s\n' "$headers" | grep -qi "^$h:"; then pass "header $h on /"; else bad "header $h missing on /"; fi
done

for path in /.env /.git/config; do
  code=$(status GET "$SITE$path")
  [ "$code" = "404" ] && pass "GET $path is 404" || bad "GET $path returned $code (expected 404)"
done

code=$(status POST "$OLD_HOST/stripe/webhook")
[ "$code" = "400" ] && pass "POST /stripe/webhook on the old CloudFront host is 400 (not redirected)" \
  || bad "POST /stripe/webhook on the old CloudFront host returned $code (expected 400)"

if [ -n "$BEANSTALK" ]; then
  BEANSTALK="${BEANSTALK#http://}"; BEANSTALK="${BEANSTALK#https://}"
  # http on purpose: the Beanstalk environment has no HTTPS listener (CloudFront
  # terminates TLS), so https:// to it would never connect.
  code=$(status GET "http://$BEANSTALK/")
  if [ "$code" = "403" ]; then pass "raw Beanstalk address is 403"
  elif [ "$code" = "000" ]; then bad "raw Beanstalk address: could not connect"
  else bad "raw Beanstalk address returned $code (expected 403)"; fi
else
  printf 'SKIP  raw Beanstalk address (pass it as the first argument)\n'
fi

exit "$fail"
