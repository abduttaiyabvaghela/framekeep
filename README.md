# Framekeep

A Laravel 13 media library with MySQL, a versioned API and background imports and ZIP exports. The browser and mobile clients share the same API.

## Run locally

Install PHP 8.3 or newer with curl, DOM, mbstring, PDO SQLite or PDO MySQL, and ZIP. Run these commands from the checkout. On Windows, use `Copy-Item .env.example .env` and `New-Item database/database.sqlite -ItemType File` for the first two commands.

```sh
cp .env.example .env
touch database/database.sqlite
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

In separate terminals run `php artisan queue:work --queue=imports` and `php artisan queue:work --queue=exports`. Use `php artisan schedule:work` for scheduled cleanup and recovery. Open `http://localhost:8000`. Run `composer test` to verify account access, pagination, downloads and ZIP exports.

## Provider access

| Platform | Source and coverage |
| --- | --- |
| Instagram | Public profile, post, reel or story link. Results depend on public data Instagram returns. Complete history, private profiles and active stories are not guaranteed. |
| Connected Instagram | A professional account owner can authorize imports of their own posts, reels and active stories. Configure a Meta app, `INSTAGRAM_APP_ID`, `INSTAGRAM_SECRET`, `INSTAGRAM_GRAPH_VERSION`, and register `https://your-host/instagram/callback`. |
| YouTube | Channel name, @handle or channel ID. Set `YOUTUBE_API_KEY` to paginate public uploads. The official API supplies watch links and metadata. |
| X | Public @username with `X_BEARER_TOKEN`. Imports media from the available timeline, with MP4 variants when the API supplies them. |
| Pinterest | Public board ID in `PINTEREST_PUBLIC_BOARDS` with a scoped `PINTEREST_ACCESS_TOKEN`. Media availability depends on the Pins API response. |

Free accounts can fetch one source and download ten unique media files per UTC day. Pro is USD $20 per year. Pro and admins can fetch multiple sources and export supported files to ZIP, subject to batch and size limits. Existing accounts keep their password hashes and configured admin IDs. Checkout is disabled. Admins have full access without a subscription.

## Development and releases

Use `dev` for implementation, `stage` for verification, and `prod` for live releases. Pull requests and pushes run the test suite against SQLite and MySQL. See [API v1](docs/api.md) for the mobile integration and [Hostinger releases](deployment/README.md) for private checkout, webhook and deployment setup.
