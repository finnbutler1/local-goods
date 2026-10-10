#!/usr/bin/env bash
# Elastic Beanstalk postdeploy hook (runs as root after every deploy).
# Rebuilds the demo SQLite database from scratch and seeds it. Demo data
# resetting on each deploy is intentional for now.
set -euo pipefail

cd /var/app/current

touch storage/database.sqlite
php artisan migrate:fresh --seed --force --no-interaction

# This script runs as root, but the web server runs as "webapp". SQLite needs
# write access to both the file and its folder (for its journal file), and
# artisan may have created root-owned log/cache files, so hand it all back.
chown -R webapp:webapp storage bootstrap/cache
chmod 664 storage/database.sqlite
chmod 775 storage
