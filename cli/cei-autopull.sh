#!/bin/bash
# cei.fis.co.tz auto-deploy, run by cron every 5 minutes:
#   */5 * * * * PATH=/usr/local/bin:/usr/bin:/bin /bin/bash $HOME/cei.fis.co.tz/cli/cei-autopull.sh >/dev/null 2>&1
# 1. git pull --ff-only (never merges; stops and logs if anything is in the way)
# 2. php cli/run-cei-migrations.php (backup first; blocks itself on a failure)
# Logs: ~/cei_autopull.log (updates and failures only), ~/cei_migrate.log.

REPO="$HOME/cei.fis.co.tz"
LOG="$HOME/cei_autopull.log"
cd "$REPO" || exit 1

before=$(git rev-parse HEAD)
if ! out=$(git pull --ff-only 2>&1); then
  echo "$(date '+%F %T') FAILED: $out" >> "$LOG"
  exit 1
fi
after=$(git rev-parse HEAD)
[ "$before" != "$after" ] && echo "$(date '+%F %T') updated ${before:0:7} -> ${after:0:7}" >> "$LOG"

# Logs to ~/cei_migrate.log itself; silent when nothing is pending.
php "$REPO/cli/run-cei-migrations.php" >/dev/null 2>&1
exit 0
