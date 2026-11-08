#!/usr/bin/env bash
# Runs one of a few approved artisan commands on the Beanstalk instance, by
# hand, as the web server's user (webapp) with the Beanstalk environment
# properties loaded, the same way scripts/run-scheduler.sh does. Run it as
# root (only root can read the properties):
#
#   sudo /var/app/current/scripts/artisan-as-webapp.sh demo:check-mail-guard
#
# Only the commands in the list below are allowed; anything else is refused
# before PHP starts. Property values are never printed.

APP_DIR="${APP_DIR:-/var/app/current}"
GET_CONFIG="${GET_CONFIG:-/opt/elasticbeanstalk/bin/get-config}"
RUNUSER_PATHS="${RUNUSER_PATHS:-/usr/sbin/runuser /sbin/runuser /usr/bin/runuser /bin/runuser}"
SU_PATHS="${SU_PATHS:-/usr/bin/su /bin/su}"
SUDO_PATHS="${SUDO_PATHS:-/usr/bin/sudo /bin/sudo}"
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin${PATH:+:$PATH}"

case "$*" in
    # One of these fixed strings, so splitting it into words below is safe.
    "demo:check-mail-guard" | "demo:update-emails" | "demo:update-emails --apply") command="$*" ;;
    *)
        echo "Refused: only these are allowed: demo:check-mail-guard, demo:update-emails, demo:update-emails --apply" >&2
        exit 2
        ;;
esac

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

cd "$APP_DIR" || { echo "FAILED: cannot open $APP_DIR." >&2; exit 1; }

properties="$("$GET_CONFIG" environment 2>/dev/null)" || { echo "FAILED: cannot read the Beanstalk environment properties (run it with sudo)." >&2; exit 1; }

# Same export as the predeploy hook. Values are never printed.
eval "$(printf '%s' "$properties" | php -r '
    foreach (json_decode(stream_get_contents(STDIN), true) ?: [] as $name => $value) {
        if (preg_match("/^[A-Za-z_][A-Za-z0-9_]*$/", $name)) {
            echo "export $name=" . escapeshellarg((string) $value) . "\n";
        }
    }
' 2>/dev/null)" 2>/dev/null

# Each keeps the exported environment (-m / -E): Laravel reads its settings from it.
if switcher="$(first_executable "$RUNUSER_PATHS")"; then
    "$switcher" -u webapp -m -- php artisan $command --no-interaction
elif switcher="$(first_executable "$SU_PATHS")"; then
    "$switcher" -m -s /bin/bash -c "exec php artisan $command --no-interaction" webapp
elif switcher="$(first_executable "$SUDO_PATHS")"; then
    "$switcher" -E -u webapp -- php artisan $command --no-interaction
else
    echo "FAILED: cannot switch to the webapp user (no runuser, su or sudo at: $RUNUSER_PATHS $SU_PATHS $SUDO_PATHS). Nothing ran." >&2
    exit 1
fi
