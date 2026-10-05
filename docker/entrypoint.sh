#!/bin/sh
set -eu
case "${1:-api}" in
  api|mock) export APP_ROLE="$1"; exec rr serve -c .rr.yaml ;;
  worker) export APP_ROLE=worker; exec supervisord -n -c /app/docker/supervisord.conf ;;
  console) shift; exec php bin/console "$@" ;;
  *) exec "$@" ;;
esac
