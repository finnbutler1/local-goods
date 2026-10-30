#!/usr/bin/env bash
# Runs Laravel's scheduler once (ADR 0032). Called every minute by the cron
# line .platform/hooks/predeploy/02_scheduler.sh installs, as root because
# only root can read the Beanstalk environment properties; PHP itself runs as
# the web server's user, webapp. Outside any web request, so a failure here
# can never break a page. Always exits 0.
#
# Today the scheduler only runs the demo activity generator, so when
# DEMO_ACTIVITY_ENABLED isn't "true" this stops before starting PHP at all.
# It reads the properties fresh every minute: setting DEMO_ACTIVITY_ENABLED
# to false in Beanstalk stops the next run.

APP_DIR="${APP_DIR:-/var/app/current}"
GET_CONFIG="${GET_CONFIG:-/opt/elasticbeanstalk/bin/get-config}"

cd "$APP_DIR" 2>/dev/null || exit 0

# Same export as the predeploy hook. Values are never printed.
eval "$("$GET_CONFIG" environment 2>/dev/null | php -r '
    foreach (json_decode(stream_get_contents(STDIN), true) ?: [] as $name => $value) {
        if (preg_match("/^[A-Za-z_][A-Za-z0-9_]*$/", $name)) {
            echo "export $name=" . escapeshellarg((string) $value) . "\n";
        }
    }
' 2>/dev/null)" 2>/dev/null || exit 0

case "$(printf '%s' "${DEMO_ACTIVITY_ENABLED:-false}" | tr '[:upper:]' '[:lower:]')" in
    true) ;;
    *) exit 0 ;;
esac

runuser -u webapp -m -- php artisan schedule:run --no-interaction
exit 0
