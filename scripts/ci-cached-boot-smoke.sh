#!/usr/bin/env bash
# General protection, CI only: with config, routes, events and views cached,
# sign in as the platform admin and load /admin. Uses a throwaway SQLite file.
# Refuses to run anywhere else, so it can't touch a real database or leave a
# cached config behind on someone's machine.
set -euo pipefail

if [ "${CI:-}" != "true" ]; then
    echo "This check only runs in CI (CI=true)." >&2
    exit 1
fi

cd "$(dirname "$0")/.."

WORK="$(mktemp -d)"
PORT=8123
BASE="http://127.0.0.1:$PORT"
JAR="$WORK/cookies"
SERVER_PID=""

cleanup() {
    [ -z "$SERVER_PID" ] || kill "$SERVER_PID" 2>/dev/null || true
    php artisan optimize:clear --no-interaction >/dev/null 2>&1 || true
    rm -rf "$WORK"
}
trap cleanup EXIT

die() {
    echo "Cached boot smoke FAILED: $1" >&2
    tail -n 60 storage/logs/laravel.log 2>/dev/null >&2 || true
    tail -n 20 "$WORK/server.log" 2>/dev/null >&2 || true
    exit 1
}

export APP_ENV=ci APP_DEBUG=false APP_URL="$BASE"
export DB_CONNECTION=sqlite DB_DATABASE="$WORK/smoke.sqlite"
export SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync
export PLATFORM_ADMIN_EMAIL="smoke.admin@example.com"
export DEMO_PASSWORD_PLATFORM_ADMIN="fake-example-smoke-password"

touch "$DB_DATABASE"
php artisan migrate --force --no-interaction
php artisan demo:seed-accounts --no-interaction
php artisan demo:seed-if-empty --no-interaction
php artisan optimize --no-interaction

# Laravel's router looks for index.php in the folder it is started from.
(cd public && exec php -S "127.0.0.1:$PORT" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) >"$WORK/server.log" 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 40); do
    curl -s -o /dev/null "$BASE/up" && break
    sleep 0.25
done

TOKEN="$(curl -s -c "$JAR" -b "$JAR" "$BASE/login" | sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' | head -n 1)"
[ -n "$TOKEN" ] || die "the login page has no form token."

CODE="$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" "$BASE/login" \
    --data-urlencode "_token=$TOKEN" \
    --data-urlencode "email=$PLATFORM_ADMIN_EMAIL" \
    --data-urlencode "password=$DEMO_PASSWORD_PLATFORM_ADMIN")"
[ "$CODE" = "302" ] || die "sign-in answered $CODE, expected 302."

CODE="$(curl -s -o "$WORK/admin.html" -w '%{http_code}' -c "$JAR" -b "$JAR" "$BASE/admin")"
[ "$CODE" = "200" ] || die "/admin answered $CODE, expected 200."
grep -q '<h1 class="page-title">Home</h1>' "$WORK/admin.html" || die "/admin did not show the Home heading."

echo "Cached boot smoke passed: signed in as platform admin and loaded /admin."
