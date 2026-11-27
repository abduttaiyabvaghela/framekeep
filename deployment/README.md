# Hostinger releases

Use PHP 8.3 or newer for both the web server and SSH/cron. The production app needs MySQL, the PHP curl, DOM, mbstring, PDO MySQL and ZIP extensions, Bash, Git, flock and Composer. Use a separate MySQL database to rehearse the release.

1. In a directory outside all web roots, create a private release directory with a persistent `.env` and writable `storage` directory. Use a new `APP_KEY` for each environment and retain it across releases. Copy the database settings from the private live config into this file without committing them. Set `APP_URL=https://framekeep.saifeewindows.com`, `SESSION_SECURE_COOKIE=true`, `APP_ENV=production`, and `APP_DEBUG=false`. Copy the live admin ID to `FRAMEKEEP_ADMIN_IDS`. Use MySQL through `DB_CONNECTION=mysql`. Payments are disabled in the application. Verify that `GET /api/v1/catalog` returns `"checkout_enabled": false` after release.
2. Connect Hostinger Git to the repository's `prod` branch and configure its webhook for push events if the panel supports a private checkout at `<release directory>/source`. The initial checkout and the subsequent webhook updates must use that directory. A shared Hostinger installation restricted to `public_html` needs the SSH option below.
3. Run `bash /absolute/framekeep/source/deployment/hostinger-release.sh /absolute/framekeep`. Rehearse against a copy of the legacy MySQL database. The migration preserves the existing `users` table and password hashes. Run `/up`, sign in with a test account, check admin access, and verify a queue-driven import before switching the live site. Back up the existing site and database before the first production migration.
4. Point the web server at `/absolute/framekeep/current/public`. If the panel uses a fixed `public_html`, use its support for symlinks to a public directory or configure the document root with Hostinger. Review any domain redirects that existed in the legacy document root before switching, including the old staging hostname. Never copy the project root, Git checkout, or `.env` into the web root.
5. Schedule the release command once a minute for webhook-managed checkouts. Run these three cron commands once a minute as separate jobs. Set the full `FRAMEKEEP_PHP_BIN` in cron if needed.

```sh
bash /absolute/framekeep/current/deployment/hostinger-jobs.sh /absolute/framekeep imports
bash /absolute/framekeep/current/deployment/hostinger-jobs.sh /absolute/framekeep exports
bash /absolute/framekeep/current/deployment/hostinger-jobs.sh /absolute/framekeep scheduler
```

The release script installs pinned dependencies, runs migrations, builds caches, and switches the active release only after preparing it successfully. A rollback repoints `current` to a known-good retained release. Make future schema changes compatible with the previous release during rollout.

## SSH deployment when the panel Git root is public

### Signed GitHub webhook

Use a private Git checkout of `prod` at `<release directory>/source`. Generate a random secret with at least 32 bytes of entropy from the server and add it to the private environment as `FRAMEKEEP_DEPLOY_WEBHOOK_SECRET`. Set `FRAMEKEEP_DEPLOY_REPOSITORY=abduttaiyabvaghela/framekeep`. After the initial release, add a GitHub repository webhook for push events with the JSON endpoint `https://framekeep.saifeewindows.com/api/v1/webhooks/github`, the same secret, and SSL verification enabled.

Schedule `bash /absolute/framekeep/source/deployment/hostinger-webhook-release.sh /absolute/framekeep` once a minute. The endpoint verifies GitHub's signature and records a private deployment request. The cron job fetches `prod` from the configured Git remote, fast-forwards the private checkout, and verifies the active release after the switch. Check the GitHub delivery status and the `current` symlink after a test push. Use this method independently of the hPanel Git integration and SSH workflow.

### GitHub Actions over SSH

The GitHub `Deploy prod to Hostinger` workflow checks the app, then uses SSH to fetch the exact pushed revision in the private checkout and run the same release script. Configure the GitHub `production` environment with variables `HOSTINGER_HOST`, `HOSTINGER_PORT`, `HOSTINGER_USER`, `HOSTINGER_RELEASE_DIR` and secrets `HOSTINGER_SSH_KEY` and `HOSTINGER_KNOWN_HOSTS`. The production checkout needs access to the GitHub repository. For a private repository, use a repository-scoped read-only deploy key on Hostinger. Use a dedicated SSH key authorized on Hostinger for the workflow and verify the known-host entry through the hosting provider. Set the repository variable `FRAMEKEEP_SSH_DEPLOY=true` after staging verification to enable workflow releases. Run either native webhook publication or the SSH workflow for the same checkout.

## Capacity

On shared hosting, use database-backed queues and run the import and ZIP workers independently. Measure the actual PHP worker, process runtime, disk, bandwidth and database limits on the account before increasing concurrency. A larger deployment can use managed MySQL, Redis for cache and queues, supervised workers per queue, and a private S3 bucket for ZIP archives. Configure the same Redis cache across all web and worker instances so locks and quotas coordinate. Start with `FRAMEKEEP_EXPORT_MAX_FILES=200` and `FRAMEKEEP_EXPORT_MAX_MIB=1024`.
