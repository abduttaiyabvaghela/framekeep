#!/usr/bin/env bash
set -euo pipefail

if [[ $# != 1 ]]; then
  echo 'Usage: bash hostinger-release.sh /absolute/private/framekeep-directory' >&2
  exit 2
fi

base="$(cd "$1" && pwd -P)"
source="$base/source"
php="${FRAMEKEEP_PHP_BIN:-php}"
composer="${FRAMEKEEP_COMPOSER_BIN:-composer}"
mkdir -p "$base/releases"
exec 9>"$base/release.lock"
flock -n 9 || exit 0

[[ -f "$base/.env" && -d "$base/storage" ]] || {
  echo 'Set up the private environment and storage before releasing.' >&2
  exit 1
}

branch="$(git -C "$source" symbolic-ref --short HEAD)"
[[ "$branch" = prod ]] || { echo 'Expected the prod branch.' >&2; exit 1; }
revision="$(git -C "$source" rev-parse HEAD)"
current="$(readlink "$base/current" || true)"
[[ "$current" != "$base/releases/$revision" ]] || exit 0

release="$base/releases/$revision"
[[ ! -e "$release" ]] || { echo 'Review the existing release before repointing current.' >&2; exit 1; }
mkdir "$release"
target="$release"
link="$base/.current.$$"
trap 'rm -f "$link"; if [[ -n "$target" && "$target" = "$base/releases/$revision" ]]; then rm -rf "$target"; fi' EXIT
git -C "$source" archive "$revision" | tar -x -C "$target"
for directory in app/private framework/cache/data framework/sessions framework/views logs; do
  mkdir -p "$base/storage/$directory"
done
mv "$target/storage" "$target/storage-template"
ln -s "$base/storage" "$target/storage"
ln -s "$base/.env" "$target/.env"

cd "$target"
"$composer" install --no-interaction --no-dev --prefer-dist --optimize-autoloader
"$php" artisan about --only=environment
# The release flock also covers migrations. The configured database cache
# cannot provide an Artisan mutex before its tables exist on the first release.
"$php" artisan migrate --force
"$php" artisan config:cache
"$php" artisan route:cache
"$php" artisan view:cache

ln -s "$release" "$link"
mv -Tf "$link" "$base/current"
target=''
"$php" "$base/current/artisan" queue:restart
printf 'Released %s\n' "$revision"
