#!/usr/bin/env bash
# Elastic Beanstalk predeploy hook (runs as root on every deploy, and on every
# configuration change via .platform/confighooks), after 01_database.sh.
#
# Installs one cron line that runs Laravel's scheduler every minute
# (scripts/run-scheduler.sh), which today only runs the demo activity
# generator (ADR 0032). It starts no PHP at all, and it can NEVER fail a
# deploy: a broken deploy weeks before judging is worse than a generator that
# isn't running. Every step is allowed to fail; failures are printed and the
# script always ends with exit 0. No "set -e" on purpose.

CRON_FILE="${CRON_FILE:-/etc/cron.d/local-goods-scheduler}"
APP_DIR="${APP_DIR:-/var/app/current}"

note() {
    echo "Scheduler hook: $1 The deploy continues; the demo activity generator won't run until this is fixed." >&2
}

install_cron() {
    local tmp="${CRON_FILE}.tmp.$$"
    {
        echo "# Local Goods (ADR 0032): Laravel's scheduler every minute. Written by .platform/hooks/predeploy/02_scheduler.sh."
        echo "# It does nothing unless DEMO_ACTIVITY_ENABLED is true in the Beanstalk environment properties."
        echo "* * * * * root /bin/bash ${APP_DIR}/scripts/run-scheduler.sh >> ${APP_DIR}/storage/logs/scheduler.log 2>&1"
    } > "$tmp" 2>/dev/null || { rm -f "$tmp" 2>/dev/null; return 1; }
    chmod 0644 "$tmp" 2>/dev/null || { rm -f "$tmp" 2>/dev/null; return 1; }
    mv -f "$tmp" "$CRON_FILE" 2>/dev/null || { rm -f "$tmp" 2>/dev/null; return 1; }
}

if install_cron; then
    echo "Scheduler hook: cron line installed at $CRON_FILE."
else
    note "could not write $CRON_FILE."
fi

exit 0
