#!/bin/sh
# Keep receiver dependency failures under Supervisor without a rapid restart loop.
set -u
child_pid=''
stop_requested=0
stop() {
  stop_requested=1
  if [ -n "$child_pid" ]; then kill -TERM "$child_pid" 2>/dev/null || true; fi
}
trap stop TERM INT
retry_delay=2
while [ "$stop_requested" -eq 0 ]; do
  started=$(date +%s)
  php /app/bin/console messenger:consume payments --time-limit=3600 --memory-limit=128M &
  child_pid=$!
  wait "$child_pid" || true
  child_pid=''
  [ "$stop_requested" -eq 0 ] || break
  elapsed=$(( $(date +%s) - started ))
  if [ "$elapsed" -ge 60 ]; then retry_delay=2; fi
  printf '{"message":"queue.receiver_restart","delay_seconds":%s}\n' "$retry_delay" >&2
  sleep "$retry_delay" &
  child_pid=$!
  wait "$child_pid" || true
  child_pid=''
  retry_delay=$((retry_delay * 2))
  [ "$retry_delay" -le 30 ] || retry_delay=30
done
