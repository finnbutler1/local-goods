#!/usr/bin/env bash
# Builds today's "Try it yourself" sandbox template (ADR 0045) by running
# `php artisan sandbox:build-template` as the web server's user, webapp.
# The scheduler normally builds the template (every five minutes); this is
# for running the same build by hand as root through Session Manager
# (`sudo /var/app/current/scripts/build-sandbox-template.sh`), because only
# root can read the Beanstalk environment properties. No hook calls it.
#
# It never fails loudly: no "set -e", every step may fail, and it always ends
# with exit 0. When SANDBOX_ENABLED isn't "true" it starts no PHP at all.
#
# Like scripts/run-scheduler.sh, the user switcher is only ever called by its
# full path (cron's and the hooks' PATH may not include /usr/sbin).

APP_DIR="${APP_DIR:-/var/app/current}"
GET_CONFIG="${GET_CONFIG:-/opt/elasticbeanstalk/bin/get-config}"
RUNUSER_PATHS="${RUNUSER_PATHS:-/usr/sbin/runuser /sbin/runuser /usr/bin/runuser /bin/runuser}"
SU_PATHS="${SU_PATHS:-/usr/bin/su /bin/su}"
SUDO_PATHS="${SUDO_PATHS:-/usr/bin/sudo /bin/sudo}"
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin${PATH:+:$PATH}"

log() {
    echo "[$(date -u '+%Y-%m-%d %H:%M:%S') UTC] build-sandbox-template: $1"
}

first_executable() {
    local candidate
    for candidate in $1; do
        if [ -x "$candidate" ]; then
            echo "$candidate"
            return 0
        fi
    done
    return 1
}

cd "$APP_DIR" 2>/dev/null || exit 0

# Same export as the predeploy hook. Values are never printed.
eval "$("$GET_CONFIG" environment 2>/dev/null | php -r '
    foreach (json_decode(stream_get_contents(STDIN), true) ?: [] as $name => $value) {
        if (preg_match("/^[A-Za-z_][A-Za-z0-9_]*$/", $name)) {
            echo "export $name=" . escapeshellarg((string) $value) . "\n";
        }
    }
' 2>/dev/null)" 2>/dev/null || exit 0

case "$(printf '%s' "${SANDBOX_ENABLED:-false}" | tr '[:upper:]' '[:lower:]')" in
    true) ;;
    *) exit 0 ;;
esac

# Each keeps the exported environment (-m / -E): Laravel reads its settings from it.
if switcher="$(first_executable "$RUNUSER_PATHS")"; then
    log "building as webapp via $switcher"
    "$switcher" -u webapp -m -- php artisan sandbox:build-template --no-interaction
elif switcher="$(first_executable "$SU_PATHS")"; then
    log "building as webapp via $switcher (runuser not found)"
    "$switcher" -m -s /bin/bash -c 'exec php artisan sandbox:build-template --no-interaction' webapp
elif switcher="$(first_executable "$SUDO_PATHS")"; then
    log "building as webapp via $switcher (runuser and su not found)"
    "$switcher" -E -u webapp -- php artisan sandbox:build-template --no-interaction
else
    log "FAILED: cannot switch to the webapp user. Nothing built; the scheduler will try again." >&2
    exit 0
fi

status=$?
if [ "$status" -ne 0 ]; then
    log "FAILED: sandbox:build-template exited $status. The deploy continues; the scheduler will try again." >&2
fi
exit 0
