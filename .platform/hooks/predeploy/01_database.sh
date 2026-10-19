#!/usr/bin/env bash
# Elastic Beanstalk predeploy hook (runs as root on every deploy, and on every
# configuration change via .platform/confighooks).
#
# Predeploy runs BEFORE Beanstalk restarts PHP with the new code or settings.
# If anything here fails, the deployment stops and the running site keeps its
# old code and old database settings instead of breaking.
#
# Applies new migrations, creates or updates the demo logins (one per role,
# passwords from DEMO_PASSWORD_* properties), and seeds the demo ONLY if the
# demo tables are empty. It never drops or wipes tables: a deploy must not delete data.
set -Eeuo pipefail

# Work in the app folder this script shipped in (Beanstalk's staging folder,
# which becomes /var/app/current once this hook succeeds).
cd "$(dirname "${BASH_SOURCE[0]}")/../../.."

# If any step fails, drop any cached Laravel config so a stale copy can never
# outlive this deployment. (Nothing caches config today; this keeps it that way.)
on_error() {
    php artisan config:clear --no-interaction >/dev/null 2>&1 || true
    echo "Deployment stopped before the site switched over; the running site is unchanged." >&2
}
trap on_error ERR

# Never print values in messages: they include the database password.
fail() {
    echo "Database step failed: $1" >&2
    on_error
    exit 1
}

# Hooks don't receive the Beanstalk environment properties automatically.
# Export them so artisan uses the same database the website will use.
GET_CONFIG="${GET_CONFIG:-/opt/elasticbeanstalk/bin/get-config}"
eval "$("$GET_CONFIG" environment | php -r '
    foreach (json_decode(stream_get_contents(STDIN), true) ?: [] as $name => $value) {
        if (preg_match("/^[A-Za-z_][A-Za-z0-9_]*$/", $name)) {
            echo "export $name=" . escapeshellarg((string) $value) . "\n";
        }
    }
')"

# Catch typos in the environment properties before touching anything.
case "${DB_CONNECTION:-sqlite}" in
    sqlite) ;;
    mysql)
        for name in DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD; do
            [ -n "${!name:-}" ] || fail "environment property $name is missing or empty."
        done
        ;;
    *) fail "DB_CONNECTION must be exactly 'mysql' or 'sqlite' (check for typos, such as the digit 1 instead of the letter l)." ;;
esac

# Stop here if a production setting is unsafe (APP_DEBUG, APP_ENV, secure
# cookies, the CloudFront secret). Prints which setting, never its value.
php artisan app:check-production-config --no-interaction

# Make sure every folder Laravel writes to exists (sessions, cache, compiled
# views, logs), even if the uploaded zip left an empty one out.
mkdir -p storage/framework/sessions storage/framework/cache/data \
    storage/framework/views storage/logs bootstrap/cache

USE_SQLITE=false
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    USE_SQLITE=true
    touch storage/database.sqlite
fi

php artisan migrate --force --no-interaction
php artisan demo:seed-accounts --no-interaction
php artisan demo:seed-if-empty --no-interaction

# This script runs as root, but the web server runs as "webapp". Artisan may
# have created root-owned log/cache files, so hand them back. SQLite also
# needs write access to the file and its folder (for its journal files).
chown -R webapp:webapp storage bootstrap/cache
if [ "$USE_SQLITE" = true ]; then
    chmod 664 storage/database.sqlite
    chmod 775 storage
fi
