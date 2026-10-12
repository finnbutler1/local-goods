#!/usr/bin/env bash
# Changing environment properties in Beanstalk (for example the DB_ settings)
# runs a configuration deployment, which uses confighooks instead of hooks.
# Run the same safe migrate-and-seed-if-empty step so the database is ready.
set -euo pipefail

exec /var/app/current/.platform/hooks/postdeploy/01_database.sh
