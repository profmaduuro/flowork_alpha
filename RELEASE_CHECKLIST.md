# Flowork release checklist

Work through this list for every new install and before every release to a live server.
Tick an item only after you have checked it on the target server, not on a developer machine.

## 1. Code

- [ ] The release is a tagged git commit. `git status` on the server shows no local changes.
- [ ] `C:/xampp/php/php.exe tests/money.php` passes: recalculation on a throwaway copy of the database, and every paid payment equals the stored net it settles.
- [ ] `C:/xampp/php/php.exe tests/smoke.php` passes on a staging copy with `APP_ENV=local`. The page check only sees PHP errors when errors are displayed, so never run it against production.
- [ ] No database dumps, `.zip` archives, `scratch/` reports or `.idea/` folders were copied to the server.

## 2. Environment (`.env`)

Start from `.env.example`. The real `.env` never goes into git.

- [ ] `APP_ENV=production`. This hides PHP errors from pages and turns off the local XAMPP database fallback (root with no password).
- [ ] `DB_HOST`, `DB_NAME`, `DB_USER` and `DB_PASS` are set. The database user is not `root` and only has rights on the Flowork database.
- [ ] `SETUP_KEY`, `BACKUP_TOKEN` and `SYSCHECK_KEY` are each either empty (the feature is off) or a fresh random value of at least 16 characters. Generate one with `php -r "echo bin2hex(random_bytes(24));"`. Never reuse a value from a developer machine, a chat or a ticket.
- [ ] TIMB credential key: set `TIMB_ENC_KEY` (32 characters or more), or keep `includes/timb_key.php` from the existing install. If you lose that key, the stored TIMB usernames and passwords cannot be decrypted and must be entered again.
- [ ] `.env` can only be read by the web server user. Requesting `https://<host>/.env` returns 403.

## 3. Docker (if deployed with `docker-compose.yml`)

- [ ] `MYSQL_ROOT_PASSWORD` and `FLOWORK_DB_PASSWORD` are set in `.env` to two different, strong random values. Compose refuses to start without them.
- [ ] `DOCKER_APP_ENV=production` (or unset; production is the default).
- [ ] `TRUSTED_PROXIES` is set to the IP address of the nginx container (`docker inspect flowork_web`). Without it every client shares one login rate-limit bucket. Never list addresses that clients can reach directly.
- [ ] Only nginx is reachable from the network. MySQL (3307) and the app (8080) stay bound to `127.0.0.1`.

Without Docker: leave `TRUSTED_PROXIES` empty unless a reverse proxy or load balancer sits in front, and then list only that proxy's address.

## 4. HTTPS

- [ ] The site is served only over HTTPS with a valid certificate. Plain HTTP redirects to HTTPS.
- [ ] Behind a TLS-terminating proxy, the proxy sends `X-Forwarded-Proto: https`, so session cookies get the `Secure` flag (see `includes/conn.php`).
- [ ] After logging in, the browser shows the session cookie as `Secure`, `HttpOnly` and `SameSite=Lax`.
- [ ] Two-factor authentication is switched on for every admin and master account.

## 5. Backups (a backup counts only once a restore has been tested)

- [ ] `BACKUP_PASSPHRASE` and `BACKUP_COPY_DIR` are set in `.env`, and the passphrase is also stored somewhere safe off the server.
- [ ] `maintenance/scheduled_backup.php` runs every night (Task Scheduler or cron). It dumps the database, bundles `.env` and the TIMB key, encrypts both and copies them to `BACKUP_COPY_DIR`. Check that the task reports exit code 0.
- [ ] Backups are copied off the server (another machine or cloud storage) at least daily.
- [ ] **Restore test:** restore the latest backup into an empty database on a separate machine, point a staging copy at it, log in and check that today's deliveries, balances and payments are there. Write down the date and how long it took:
  - Last tested restore: ____________ by ____________ (duration ______)
- [ ] `.env` and `includes/timb_key.php` (or `TIMB_ENC_KEY`) are in the backup (the `secrets_*` file). A database restore is no use without them. Decrypt with `php maintenance/scheduled_backup.php --decrypt <file>.enc <out>`.
- [ ] `uploads/` (company logos and documents) is included in the file backup.

## 6. After going live

- [ ] `maintenance/` and `scratch/` return 403 over HTTP (both have a deny-all `.htaccess`; on nginx, deny them in the server block). Run those scripts only from the command line, e.g. `php maintenance/setup_database.php`.
- [ ] On a new install, `php maintenance/setup_database.php` prints a one-time password for `sysadmin` and for the platform `developer` account. Log in and change both.
- [ ] Change the passwords of any default or shared accounts. Remove test users.
- [ ] Watch the PHP error log for the first day of trading.
