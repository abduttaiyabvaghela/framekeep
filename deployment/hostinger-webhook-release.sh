#!/usr/bin/env bash
set -euo pipefail

base="$(cd "$1" && pwd -P)"
source="$base/source"
request="$base/storage/app/private/prod-deploy-request"
completed="$base/deployed-webhook-request"

[[ -f "$request" ]] || exit 0
exec 9>"$base/webhook-release.lock"
flock -n 9 || exit 0
read -r requested < "$request"
[[ "$requested" =~ ^[a-f0-9]{40}$ ]] || exit 1
[[ "$requested" != "$(cat "$completed" 2>/dev/null || true)" ]] || exit 0
[[ "$(git -C "$source" symbolic-ref --short HEAD)" = prod ]] || exit 1

git -C "$source" fetch --no-tags origin refs/heads/prod
revision="$(git -C "$source" rev-parse FETCH_HEAD)"
git -C "$source" merge --ff-only "$revision"
bash "$source/deployment/hostinger-release.sh" "$base"
[[ "$(readlink "$base/current")" = "$base/releases/$revision" ]] || exit 1
printf '%s\n' "$requested" > "$completed"
printf 'Processed prod webhook for %s, active release %s\n' "$requested" "$revision"
