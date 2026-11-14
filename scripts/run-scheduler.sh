#!/usr/bin/env bash
# Runs Laravel's scheduler once (ADR 0032). Called every minute by the cron
# line .platform/hooks/predeploy/02_scheduler.sh installs, as root because
# only root can read the Beanstalk environment properties; PHP itself runs as
# the web server's user, webapp. Outside any web request, so a failure here
# can never break a page. Exits 0, except when it cannot switch to webapp
# (exits 1) or the scheduler itself fails (its exit code), so a failure shows
# in storage/logs/scheduler.log instead of passing silently.
#
# The scheduler runs the demo activity generator and the sandbox cleanup
# (ADR 0045), so unless DEMO_ACTIVITY_ENABLED or SANDBOX_ENABLED is "true"
# this stops before starting PHP at all. It reads the properties fresh every
# minute: setting both to false in Beanstalk stops the next run. The
# generator still refuses on its own unless DEMO_ACTIVITY_ENABLED is true.
#
# Cron's PATH is only /usr/bin:/bin, which left "runuser: command not found"
# on Amazon Linux (it lives in /usr/sbin). So the PATH is set here, and the
# command that switches user is only ever called by its full path, from the
# lists below (tried in order: runuser, then su, then sudo).

APP_DIR="${APP_DIR:-/var/app/current}"
GET_CONFIG="${GET_CONFIG:-/opt/elasticbeanstalk/bin/get-config}"
RUNUSER_PATHS="${RUNUSER_PATHS:-/usr/sbin/runuser /sbin/runuser /usr/bin/runuser /bin/runuser}"
SU_PATHS="${SU_PATHS:-/usr/bin/su /bin/su}"
SUDO_PATHS="${SUDO_PATHS:-/usr/bin/sudo /bin/sudo}"
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin${PATH:+:$PATH}"

log() {
    echo "[$(date -u '+%Y-%m-%d %H:%M:%S') UTC] run-scheduler: $1"
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

is_true() {
    [ "$(printf '%s' "${1:-false}" | tr '[:upper:]' '[:lower:]')" = "true" ]
}

if ! is_true "${DEMO_ACTIVITY_ENABLED:-false}" && ! is_true "${SANDBOX_ENABLED:-false}"; then
    exit 0
fi

# Each keeps the exported environment (-m / -E): Laravel reads its settings from it.
if switcher="$(first_executable "$RUNUSER_PATHS")"; then
    log "running schedule:run as webapp via $switcher"
    "$switcher" -u webapp -m -- php artisan schedule:run --no-interaction
elif switcher="$(first_executable "$SU_PATHS")"; then
    log "running schedule:run as webapp via $switcher (runuser not found)"
    "$switcher" -m -s /bin/bash -c 'exec php artisan schedule:run --no-interaction' webapp
elif switcher="$(first_executable "$SUDO_PATHS")"; then
    log "running schedule:run as webapp via $switcher (runuser and su not found)"
    "$switcher" -E -u webapp -- php artisan schedule:run --no-interaction
else
    log "FAILED: cannot switch to the webapp user (no runuser, su or sudo at: $RUNUSER_PATHS $SU_PATHS $SUDO_PATHS). Nothing ran." >&2
    exit 1
fi

status=$?
if [ "$status" -ne 0 ]; then
    log "FAILED: schedule:run via $switcher exited $status." >&2
    exit "$status"
fi
exit 0
