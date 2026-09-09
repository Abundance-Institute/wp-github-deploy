# Abundance WordPress deployment operations

Verified 2026-09-09.

## Publishing and deployment

- WordPress timezone is `America/New_York`. The system scheduler runs due WordPress events every minute as `www-data`, independently of web traffic.
- Scheduler wrapper: `/usr/local/sbin/abundance-wp-cron`. It uses an exclusive lock and a four-minute execution limit. Failures go to `/var/log/abundance-wp-cron.log`, rotated daily with 14 retained logs.
- Normal content edits retain the configured five-minute debounce. Scheduled publications bypass that debounce when processed by WordPress cron.
- Plugin 1.1.0 persists a job before dispatch, correlates the exact GitHub run using `deployment_id`, and records accepted requests separately from completed deployments. It retries failed or missing runs twice. Exhausted retries appear as an administrator notice. A GitHub status outage does not trigger duplicate builds.
- The website workflow must accept `deployment_id` and preserve the `WordPress deploy {0}` run-name convention in README.md.
- The website build retries transient WordPress failures and rejects incomplete pagination. Before S3 upload, `scripts/verify-export.ts` checks every published article and writes `deploy-manifest.json`. Unavailable private/deleted media uses a neutral placeholder; server failures still stop the build.
- Production deploys are serialized. Old hashed `_next` files are retained so open or cached pages continue to load their scripts. CloudFront invalidation also runs if an upload fails after AWS credentials are configured.
- CloudFront now returns HTTP 404, using `/404/index.html`, for missing objects reported by S3 as 403 or 404.

## Server and backups

- Root EBS storage grew from 8 GiB to 16 GiB online. The filesystem was expanded and a 1 GiB swap file added with swappiness 10.
- PHP-FPM is limited to three workers, one starting worker, and two spare workers, with a 256 MiB per-request memory limit. The static build uses two workers with page concurrency four.
- EC2 snapshots for September 7, 8, and 9 were confirmed completed. These are the user's daily backup mechanism; no second scheduled backup system was added. This check verified snapshot completion, not a restore exercise.
- Old Updraft archives were moved outside the document root to `/var/backups/wordpress-updraft`; the plugin setting points there.
- All ten available WordPress plugin updates were installed. WordPress core was already current and passed its official checksum check.
- The retired `beehiiv/v1` and `brevo/v1` endpoint plugins were deactivated and archived outside the web root. `newsletter/v1/subscribe` remains registered.

## Security incident findings

The WordPress document root contained a publicly readable Git directory and backup archives. Nginx now rejects hidden paths (except `.well-known`), Updraft paths, and PHP execution under uploads. The Git directory was moved outside the document root. Public checks confirmed HTTP 404 for the previously exposed Git and backup paths.

Retained access logs from August 26 through September 9 contained 1,433 successful or partial-content Git requests, including 724 object requests. They contained no successful backup archive downloads. This limited log window cannot establish whether archives were accessed earlier.

The repository had one reachable commit, but also many unreferenced file objects. All 2,902 stored file objects were compared against current configured secrets. A second comparison included the decrypted GitHub deployment token, Stripe live/test secrets, database password, newsletter provider keys, Turnstile secret, and WordPress authentication keys/salts: no matches. A generic pattern scan covered 2,901 objects; its private-key marker came from the PHP-JWT README. This is not proof that no historical secret was ever exposed.

Evidence, metadata-only scan reports, previous configuration files, and pre-update database/plugin copies are retained in the root-only directory `/var/backups/abundance-security-20260909T154440Z` on the WordPress server. Do not move those files into the document root or commit their contents.

## Verification

- Seven API/media tests and eleven deployment-tracker checks passed; TypeScript and PHP syntax checks passed.
- The local production build exported 349 pages. The pre-upload check verified all 323 published articles.
- Production run `34375048222` succeeded at website commit `ad9ee41689b49edf765cff9dc292fe50d3237cc3`. The real server cron recorded its completed status and removed the durable job without manual intervention.
- An earlier run stopped safely when plugin-update maintenance returned 503; no site files were uploaded by that failed run.

## Maintenance

Avoid running WordPress plugin updates during a website build: maintenance mode temporarily returns 503. After maintenance, dispatch another deployment if an untracked push build failed. Plugin-tracked deployments retry automatically.

For a failed deployment, check the plugin's persistent notice, the exact linked GitHub Actions run, and the cron failure log. Confirm the public deployment manifest matches the intended commit and article set before treating the deployment as complete.
