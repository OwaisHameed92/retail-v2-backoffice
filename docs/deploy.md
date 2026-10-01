# Deploy runbook

Production: one Ubuntu 24.04 server running Nginx, PHP 8.4-FPM, MySQL 8, Supervisor (queue workers) and cron
(scheduler, backups), behind HTTPS from Let's Encrypt. Redis is optional. Everything the steps below copy lives in
`deploy/`; the production settings template is `.env.production.example`.

| File | Goes to | Does |
|---|---|---|
| `deploy/server-setup.sh` | run once as root | packages, `retail` user, folders, configs below, firewall, sudo rule |
| `deploy/php-fpm-pool.conf`, `deploy/php-production.ini` | `/etc/php/8.4/fpm/…` | pool as the app user, opcache, upload limits |
| `deploy/nginx/retail-v2.conf` | `/etc/nginx/sites-available/` | site, 64 MB bodies (till pushes), only `index.php` executes |
| `deploy/supervisor/retail-v2-worker.conf` | `/etc/supervisor/conf.d/` | 2 × `queue:work` |
| `deploy/cron/retail-v2` | `/etc/cron.d/` | `schedule:run` every minute, nightly backup |
| `deploy/logrotate/retail-v2` | `/etc/logrotate.d/` | `laravel.log`, worker/backup logs, 14 days |
| `deploy/backup-db.sh` | `/usr/local/bin/retail-v2-backup-db` | `mysqldump` + uploads, gzip, 14-day retention, optional offsite |
| `deploy/deploy.sh`, `deploy/rollback.sh` | run from the repo | zero-downtime release + automatic rollback |

Folder layout on the server:

```
/var/www/retail-v2/
  current -> releases/20261001083000     Nginx root is current/public; workers and cron run current/artisan
  releases/<UTC timestamp>/              one per deploy, last 5 kept
  shared/.env                            production settings (chmod 600)
  shared/storage/                        logs, uploads, framework cache: survives deploys
```

## 1. Server (once)

Checklist (2 vCPU / 4 GB RAM / 40 GB SSD is plenty to start; UK region; the server clock on UTC):

1. DNS: an `A` (and `AAAA`) record for the portal host, e.g. `portal.switchandsave.co.uk`, pointing at the server.
2. SSH in as a sudo user with key login only (`PasswordAuthentication no` in `/etc/ssh/sshd_config`).
3. `timedatectl set-timezone UTC` (the app stores UTC and displays Europe/London itself).
4. Copy the repo (or just `deploy/`) to the server and run:
   `sudo DOMAIN=portal.switchandsave.co.uk bash deploy/server-setup.sh` (add `INSTALL_REDIS=yes` for Redis).
5. MySQL: `sudo mysql_secure_installation`, then create the database and two users (passwords from a manager):

   ```sql
   CREATE DATABASE retail_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'retail_v2'@'127.0.0.1' IDENTIFIED BY '…';
   GRANT ALL PRIVILEGES ON retail_v2.* TO 'retail_v2'@'127.0.0.1';
   CREATE USER 'retail_backup'@'127.0.0.1' IDENTIFIED BY '…';
   GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT ON retail_v2.* TO 'retail_backup'@'127.0.0.1';
   ```

   MySQL listens on localhost only (Ubuntu default `bind-address = 127.0.0.1`; the firewall blocks 3306 anyway).
6. Backup credentials: as the `retail` user, create `~/.retail-v2-backup.cnf` (chmod 600) with a `[client]` section
   (`user=retail_backup`, `password=…`, `host=127.0.0.1`). Set `OFFSITE_CMD` in `/etc/cron.d/retail-v2` to copy
   each dump off the server (rclone / `aws s3 cp`); a backup on the same disk does not survive losing the server.
7. `.env`: `sudo -u retail cp .env.production.example /var/www/retail-v2/shared/.env`, `chmod 600`, fill every
   `CHANGE_ME`. `APP_KEY` is generated in step 3.1 below, on the server.
8. HTTPS: `sudo certbot --nginx -d portal.switchandsave.co.uk --redirect -m ops@switchandsave.co.uk --agree-tos`.
   Renewal is the `certbot.timer` systemd unit; check with `sudo certbot renew --dry-run`. Tills only talk HTTPS.
9. Deploy access: give the `retail` user a read-only deploy key on the git host (`ssh-keygen -t ed25519`, add the
   `.pub` as a deploy key).

## 2. Configuration notes

- `APP_KEY` encrypts the licence signing secret and keys the HMACs of licence keys, sync keys and device ids. Store it
  in the password manager next to the DB password. Losing it means re-issuing every licence key and sync key and
  rotating the signing key. To rotate deliberately: move the old value to `APP_PREVIOUS_KEYS`, set a new
  `APP_KEY`, then `php artisan licence:keys:rotate` (docs/DECISIONS.md, "APP_KEY").
- `QUEUE_CONNECTION=database` and `CACHE_STORE=database` work with no extra service. With Redis, switch both to
  `redis`. `reports:process-dirty` (every minute) re-queues any lost report rebuild, so a worker restart loses nothing.
- `LICENCE_ALLOW_UNCERTIFIED=false`: production signs licence tokens only with the owner's signer certificate.
- Mail must be a real SMTP relay: welcome emails carry licence keys and invoices go out by email.
- After any `.env` change: `cd /var/www/retail-v2/current && php artisan optimize && php artisan queue:restart`
  and `sudo systemctl reload php8.4-fpm` (config is cached).

## 3. First deploy

As the `retail` user on the server, from a checkout of the repo:

1. `APP_KEY`, generated on the server and never anywhere else:
   `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"` (the same format as
   `php artisan key:generate --show`); paste it into `shared/.env` and into the password manager.
2. `REPO=git@github.com:<org>/retail-v2-backoffice.git REF=main HEALTH_URL=https://portal.switchandsave.co.uk/up bash deploy/deploy.sh`
   (clone, build, migrate, cache, switch, health check). Then `cd /var/www/retail-v2/current` for the steps below.
3. Signing key and certificate (contract §17.2, §17.17; never generated anywhere but this server):
   - `php artisan licence:keys:generate` creates the Ed25519 key (secret encrypted with `APP_KEY`).
   - `php artisan licence:keys:handover --path=/tmp/handover.json` prints the public-key hand-over JSON (no secrets).
     Send it with the base URL to the SSPOS owner / EPOS team.
   - When the owner replies with the `SSPOSCERT1…` string: `php artisan licence:keys:import-cert 'SSPOSCERT1…'`.
   - `php artisan licence:keys:list` shows the active key with its certificate.
4. First admin: `php artisan admin:create you@switchandsave.co.uk --name="…" --role=owner`, then sign in at
   `/admin/login` and set up two-factor if offered.
5. Plans: create the plans on `/admin/plans` (production never seeds them); one must have the code in
   `LICENCE_DEFAULT_PLAN` (`standard`).
6. Workers: `sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status`.
7. Reporting tables: `php artisan reports:rebuild` (all businesses, chunked per shop and 7 days), then
   `php artisan reports:check` should report no differences. Run both again after importing or migrating data.
8. Check: `/up` returns 200; `/admin` System health shows database, jobs, scheduler (green within a minute) and the
   signing key with its certificate; check mail delivery with a password reset (`/forgot-password`).

Never run `db:seed` or `demo:sales` in production (both refuse outside local/testing, but do not rely on it).

## 4. Every deploy

`REPO=… REF=main HEALTH_URL=https://portal.switchandsave.co.uk/up bash deploy/deploy.sh`

It clones the ref into a new release folder, links `shared/.env` and `shared/storage`, runs
`composer install --no-dev -o`, `npm ci && npm run build`, `artisan migrate --force --isolated`, `artisan optimize`,
then atomically moves `current`, reloads PHP-FPM (opcache), runs `queue:restart`, and polls `HEALTH_URL`. If the check
fails it puts the previous release back and exits 1. The last 5 releases are kept.

- Migrations run while the old code still serves, so they must be backward compatible: add columns/tables first,
  drop or rename in a later deploy. For an unavoidable breaking migration use `MAINTENANCE=1` (tills get 503 with
  `Retry-After` and retry; portal users see the maintenance page for the minute it takes).
- Before a deploy with heavy migrations, take a backup: `/usr/local/bin/retail-v2-backup-db`.
- CI (`.github/workflows/ci.yml`) must be green on the commit, including the MySQL 8 job.

## 5. Rollback

- Code: `bash deploy/rollback.sh` (the release before `current`) or `RELEASE=20261001083000 bash deploy/rollback.sh`.
  It moves `current`, re-caches, reloads PHP-FPM and restarts workers. Seconds, no data change.
- Schema: rollback does not reverse migrations; the previous release runs on the newer schema because migrations are
  backward compatible. If a migration damaged data, restore instead of `migrate:rollback`:
  1. `php artisan down --retry=60` (tills queue offline and retry).
  2. `gunzip -c /var/backups/retail-v2/retail_v2-<stamp>.sql.gz | mysql --defaults-extra-file=~/.retail-v2-admin.cnf retail_v2`
     (an option file for the `retail_v2` user; restore into a scratch database first if unsure).
  3. `bash deploy/rollback.sh`, `php artisan up`, then `php artisan reports:rebuild` for the affected days.
  Tills re-push anything newer than the backup on their next sync (their outbox is idempotent).

## 6. Operations

- Health: `GET /up` (Laravel health route, 200 when the app boots) for uptime monitors and load balancers. Deeper
  status (DB, queue backlog and failures, scheduler heartbeat, signing key) is on the admin dashboard.
- Logs: `shared/storage/logs/laravel.log`, `/var/log/retail-v2/{worker,backup,php-fpm-error}.log`,
  `/var/log/nginx/retail-v2.*.log`; rotated daily, 14 kept, by logrotate.
- Failed jobs: `php artisan queue:failed`, `php artisan queue:retry all`.
- Scheduler: `php artisan schedule:list`. Jobs it runs: `licences:refresh`, `billing:run`,
  `billing:reconcile-gocardless`, `till-health:refresh`, `reports:process-dirty`, `licence:keys:prune`, `model:prune`.
- Backups: nightly 02:15 UTC, 14 days local (`RETENTION_DAYS`), incomplete dumps are rejected. Test a restore into a
  scratch database monthly.
- Signing key rotation: `php artisan licence:keys:rotate`, then hand-over + `licence:keys:import-cert` as in 3.3. The
  retired key keeps verifying for `LICENCE_RETIRED_KEY_KEEP_DAYS` (60).
- Security updates: `sudo unattended-upgrades` is on by default on Ubuntu; reboot in a quiet hour when needed
  (tills work offline and catch up).
