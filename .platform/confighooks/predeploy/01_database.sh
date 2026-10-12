#!/usr/bin/env bash
# Changing environment properties in Beanstalk (for example the DB_ settings)
# runs a configuration deployment, which uses confighooks instead of hooks.
# Run the same safe check-migrate-seed step, before PHP restarts with the new
# settings, so a mistake stops the change instead of breaking the site.
set -euo pipefail

exec "$(dirname "${BASH_SOURCE[0]}")/../../hooks/predeploy/01_database.sh"
