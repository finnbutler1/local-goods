#!/usr/bin/env bash
# Configuration changes (environment properties) run confighooks instead of
# hooks. Re-install the scheduler's cron line the same way. Like the hook it
# runs, this can never fail the change: it always ends with exit 0.

/bin/bash "$(dirname "${BASH_SOURCE[0]}")/../../hooks/predeploy/02_scheduler.sh"

exit 0
