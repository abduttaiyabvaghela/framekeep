#!/usr/bin/env bash
set -euo pipefail

base="$(cd "$1" && pwd -P)"
php="${FRAMEKEEP_PHP_BIN:-php}"
role="${2:-}"
[[ -f "$base/current/artisan" ]] || exit 0

case "$role" in
  imports|exports)
    exec flock -n "$base/worker-$role.lock" "$php" "$base/current/artisan" queue:work \
      --queue="$role" --stop-when-empty --max-time=55 --memory=256 --no-interaction
    ;;
  scheduler)
    exec flock -n "$base/scheduler.lock" "$php" "$base/current/artisan" schedule:run --no-interaction
    ;;
  *) exit 2 ;;
esac
