#!/usr/bin/env bash
# Elastic Beanstalk postdeploy hook (runs as root after every deploy, and
# after every configuration change via .platform/confighooks).
#
# Applies new migrations and seeds the demo ONLY if the demo tables are
# empty. It never drops or wipes tables: a deploy must not delete data.
set -euo pipefail

cd /var/app/current

# Hooks don't receive the Beanstalk environment properties automatically.
# Export them so artisan uses the same database as the website (RDS MySQL
# when DB_CONNECTION=mysql is set, otherwise the SQLite file).
eval "$(/opt/elasticbeanstalk/bin/get-config environment | php -r '
    foreach (json_decode(stream_get_contents(STDIN), true) ?: [] as $name => $value) {
        if (preg_match("/^[A-Za-z_][A-Za-z0-9_]*$/", $name)) {
            echo "export $name=" . escapeshellarg((string) $value) . "\n";
        }
    }
')"

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
php artisan demo:seed-if-empty --no-interaction

# This script runs as root, but the web server runs as "webapp". Artisan may
# have created root-owned log/cache files, so hand them back. SQLite also
# needs write access to the file and its folder (for its journal files).
chown -R webapp:webapp storage bootstrap/cache
if [ "$USE_SQLITE" = true ]; then
    chmod 664 storage/database.sqlite
    chmod 775 storage
fi
