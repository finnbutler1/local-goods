#!/usr/bin/env bash
# Scans every line ever added in this repository's git history (all branches)
# for things that look like secrets: Stripe and Anthropic keys, AWS access
# keys, Laravel APP_KEYs, RDS endpoints, private keys, and values assigned to
# our secret settings (STRIPE_SECRET=..., DB_PASSWORD=..., and so on).
#
# Read-only: it never changes the repository or its history.
#
# It never prints a matched value. A hit shows only the commit, the file, the
# pattern name and a masked value (for example sk_live_****).
#
# Exit codes: 0 = full history scanned, nothing found; 1 = something found;
# 2 = the clone is shallow, so only part of the history could be scanned
# (fetch the full history first: git fetch --unshallow).
set -Eeuo pipefail

cd "$(git rev-parse --show-toplevel)"

commits=$(git rev-list --all | wc -l | tr -d ' ')

if [ "$(git rev-parse --is-shallow-repository)" = "true" ]; then
    echo "Shallow history: only ${commits} commits scanned would be partial, so nothing was scanned." >&2
    echo "Fetch the full history first (git fetch --unshallow, or fetch-depth: 0 in CI)." >&2
    exit 2
fi

git log --all -p --no-color --no-ext-diff --format='commit %H' | COMMITS="$commits" perl -ne '
    BEGIN {
        # name => pattern; capture group 1 is the part that is safe to show.
        @patterns = (
            [stripe_secret_key      => qr/((?:sk|rk)_(?:live|test)_)[A-Za-z0-9]{10,}/],
            [stripe_publishable_key => qr/(pk_(?:live|test)_)[A-Za-z0-9]{10,}/],
            [stripe_webhook_secret  => qr/(whsec_)[A-Za-z0-9]{10,}/],
            [anthropic_api_key      => qr/(sk-ant-)[A-Za-z0-9_-]{10,}/],
            [aws_access_key         => qr/\b((?:AKIA|ASIA))[0-9A-Z]{16}\b/],
            [laravel_app_key        => qr/(base64:)[A-Za-z0-9+\/]{40,}={0,2}/],
            [rds_endpoint           => qr/()[a-z0-9.-]+(?=\.rds\.amazonaws\.com)/],
            [private_key            => qr/(-----BEGIN [A-Z ]*PRIVATE KEY-----)/],
            [secret_setting         => qr/\b((?:APP_KEY|DB_PASSWORD|STRIPE_SECRET|STRIPE_WEBHOOK_SECRET|STRIPE_CONNECT_WEBHOOK_SECRET|ANTHROPIC_API_KEY|CLOUDFRONT_ORIGIN_SECRET|AWS_SECRET_ACCESS_KEY|DEMO_PASSWORD_[A-Z_]+)\s*(=|:)\s*["\x27]?)(?![\$"\x27\s#]|null\b|\$\{)[^\s"\x27#]+/],
        );
        # Obvious test placeholders already in the history (see TRANSPARENCY.md).
        $placeholder = qr/SECRETVALUE|SHOULDNOTSHOW|fake|not-real|not_real|example|placeholder|dummy/i;
        # The throwaway password of the CI-only MySQL service.
        %allowed = map { $_ => 1 } ("DB_PASSWORD: password");
        $hits = 0;
    }
    if (/^commit ([0-9a-f]{40})$/) { $commit = $1; next; }
    if (/^\+\+\+ (?:b\/)?(.*)$/) { $file = $1; next; }
    next unless /^\+/;
    next if $file eq "scripts/scan-history-for-secrets.sh";
    for my $p (@patterns) {
        my ($name, $re) = @$p;
        while (/$re/g) {
            my ($whole, $shown) = ($&, $1);
            next if $name eq "secret_setting" && $2 eq ":" && $file !~ /\.(?:ya?ml|config|json)$/;
            next if $whole =~ $placeholder || $allowed{$whole};
            $shown .= "****";
            $shown .= ".rds.amazonaws.com" if $name eq "rds_endpoint";
            print "Found $name in commit ", substr($commit, 0, 12), ", file $file (value masked: $shown)\n";
            $hits++;
        }
    }
    END {
        if ($hits) {
            print "$hits possible secret(s) in $ENV{COMMITS} commits. Rotate each one; do not rewrite history.\n";
            exit 1;
        }
        print "Scanned $ENV{COMMITS} commits (full history, all branches): no secrets found.\n";
    }
'
