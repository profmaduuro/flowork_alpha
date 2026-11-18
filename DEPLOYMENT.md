# Flowork deployment

How Flowork runs on the floor PC today, how to turn on HTTPS for the LAN, and how to add a new customer. See RELEASE_CHECKLIST.md for the full list to tick before going live.

## 1. This PC (GENIUSENGINEER) — current state

| Item | State |
|---|---|
| App folder | `C:\xampp\htdocs\flowork_alpha`, git repository, nothing pushed |
| Apache, MySQL | Windows services `Apache2.4` and `mysql`, start automatically |
| `APP_ENV` | `production` (PHP errors go to `C:\xampp\apache\logs\error.log`, not to pages) |
| Database user | `flowork_alpha`, rights on database `king` only (password in `.env`) |
| Admin 2FA | required (`REQUIRE_ADMIN_2FA=1`): admins set it up at their next login |
| Backups | task `\Flowork\Nightly backup`, 23:30, runs `php-win.exe` (no window); if the PC is off it runs at the next start; log `backups\scheduled_backup.log`. Encrypted with `BACKUP_PASSPHRASE` and copied to `C:\FloworkBackups` (same disk for now — move `BACKUP_COPY_DIR` to an external drive or cloud folder) |
| HTTPS | certificate ready in `C:\xampp\apache\conf\flowork-ssl`; **active after Apache restarts** |
| Previous `.env` | `backups\env_before_production_*` (to roll back: copy it back over `.env`) |

The second copy at `C:\xampp\htdocs\flowork` still uses the same `king` database as MySQL `root` without a password. Retire it or give it its own database and user, then set a root password (section 7).

## 2. Turn on HTTPS on the LAN

The server certificate covers `https://GENIUSENGINEER/`, `https://192.168.1.23/`, `https://192.168.0.100/` and `https://localhost/`. It is signed by a private authority, the **Flowork Local CA**, which each device must trust once.

1. **Restart Apache as administrator.** Use the XAMPP Control Panel started with "Run as administrator" (Apache → Stop, then Start), or `services.msc` → Apache2.4 → Restart. The restart loads the new certificate and switches on the HTTP→HTTPS redirect in `.htaccess` (it is tied to `Define FLOWORK_HTTPS` in `conf\extra\httpd-ssl.conf`).
2. **Check it:** open `https://192.168.1.23/flowork_alpha/` on this PC. Before the CA is trusted the browser warns; that is expected.
3. **Trust the CA on every device** that uses Flowork. Copy `C:\xampp\apache\conf\flowork-ssl\ca.crt` (by USB or email; it is public). **Never copy `ca.key`**: whoever has it can make certificates your devices trust.
   - Windows: double-click `ca.crt` → Install Certificate → Local Machine → "Trusted Root Certification Authorities".
   - Android: Settings → Security → Encryption & credentials → Install a certificate → CA certificate.
   - iPhone/iPad: open the file → install the profile → Settings → General → About → Certificate Trust Settings → switch on "Flowork Local CA".
4. **Keep the address fixed.** The PC moved from `192.168.0.100` to `192.168.1.23` on 19 Oct 2026 (after a restart), which breaks bookmarks and handhelds. Reserve `192.168.1.23` for this PC in the router (DHCP reservation). If the address changes, issue a new server certificate (section 4) — the devices keep trusting the CA.
5. **Mobile app / scales:** requests under `/flowork_alpha/api/` and requests from this PC itself are not redirected yet. Once the handhelds trust the CA, point them at `https://` and remove the `api/` line from the redirect block in `.htaccess`.

To undo: restore `conf\extra\httpd-ssl.conf.before-flowork-*` over `httpd-ssl.conf` and restart Apache.

## 3. Add a customer (one database per customer)

Each customer gets its own database, its own MySQL user and its own app folder with its own `.env`. Customers then cannot see each other's data, including the processing, packhouse, dispatch and transporter records that have no floor column.

```
cd C:\xampp\htdocs\flowork_alpha
C:\xampp\php\php.exe maintenance\provision_customer.php --db=flowork_goldleaf --floor="Golden Leaf Tobacco" --code=GL --months=12
```

It prints the one-time `sysadmin` and platform passwords and writes `backups\provision_flowork_goldleaf.env`. Then:

1. Copy the release into the customer's own folder, e.g. `git -C C:\xampp\htdocs\flowork_alpha archive --format=zip -o goldleaf.zip HEAD`, unzip to `C:\xampp\htdocs\goldleaf`.
2. Move `backups\provision_flowork_goldleaf.env` to `C:\xampp\htdocs\goldleaf\.env` (and delete it from `backups`).
3. Open `https://GENIUSENGINEER/goldleaf/`, log in as `sysadmin`, set up 2FA, change the password, create the customer's users.
4. Add a nightly backup task for that folder (copy `\Flowork\Nightly backup` and change both paths).

A test customer can be removed with `--drop --db=<name> --confirm=<name>`.

Settings are read per app from its own `.env` (never from `putenv()`), so several customers on this Apache do not leak settings into each other. In Docker, `FLOWORK_TRUST_PROCESS_ENV=1` lets the container's variables win.

## 4. Renew or reissue the server certificate

Every 825 days (next: January 2029) or when the PC's name/address changes:

```
cd C:\xampp\apache\conf\flowork-ssl
set OPENSSL_CONF=C:\xampp\apache\conf\openssl.cnf
C:\xampp\apache\bin\openssl.exe req -newkey rsa:2048 -sha256 -nodes -keyout server.key -out server.csr -subj "/O=Flowork/CN=GENIUSENGINEER"
C:\xampp\apache\bin\openssl.exe x509 -req -in server.csr -CA ca.crt -CAkey ca.key -CAcreateserial -out server.crt -days 825 -sha256 -extfile server.ext
```

Edit `server.ext` first if the address changed, then restart Apache. Devices need nothing new.

## 5. Online server (VPS) with Docker

Caddy (HTTPS, Let's Encrypt) → PHP/Apache app → MariaDB 10.11, all from `docker-compose.yml`. A Linux VPS (Ubuntu 24.04, 2 GB RAM or more) with a domain name pointed at it.

1. **DNS:** an A record such as `flowork.example.com` → the VPS's IP address.
2. **Firewall:** allow only 22, 80 and 443 (`ufw allow OpenSSH && ufw allow 80,443/tcp && ufw enable`). Docker publishes the database, app and phpMyAdmin on `127.0.0.1` only.
3. **Install Docker** (`curl -fsSL https://get.docker.com | sh`) and get the code: `git clone https://github.com/profmaduuro/flowork_alpha.git /opt/flowork && cd /opt/flowork`.
4. **`.env`:** `cp .env.example .env`, then set `APP_ENV=production`, `FLOWORK_DOMAIN`, `MYSQL_ROOT_PASSWORD`, `FLOWORK_DB_PASSWORD`, `BACKUP_PASSPHRASE` and new values for `SETUP_KEY`/`BACKUP_TOKEN`/`SYSCHECK_KEY` (`openssl rand -hex 24`). `chmod 600 .env`. Carry over `includes/timb_key.php` (or `TIMB_ENC_KEY`) from the floor PC, or the saved TIMB logins must be entered again.
5. **Start:** `docker compose up -d --build`. Caddy gets the certificate within a minute (`docker compose logs web`).
6. **Database**, one of:
   - new install: `docker compose exec app php maintenance/setup_database.php` (prints one-time passwords; `sysadmin` is the master user);
   - move the floor PC's data: on the PC `C:\xampp\mysql\bin\mysqldump.exe --single-transaction --routines -u root king > king.sql`, copy it to the VPS by `scp`, then `docker compose exec -T db mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" king < king.sql`. Delete `king.sql` on both machines afterwards.
7. **Uploads:** copy the PC's `uploads/` folder (logos, documents) to `/opt/flowork/uploads/`.
8. **Backups:** cron on the VPS, e.g. `30 23 * * * cd /opt/flowork && docker compose exec -T app php maintenance/scheduled_backup.php >> backups/scheduled_backup.log 2>&1`, and copy `backups/` off the server (another machine or cloud storage).
9. **Check:** `https://<domain>/.env`, `/maintenance/` and `/scratch/` return 403; log in, set up 2FA, change the one-time passwords; RELEASE_CHECKLIST.md sections 2–6.

**No master user** (no "Master user" badge, no floor switcher): `docker compose exec app php maintenance/grant_master.php` lists the users; `... grant_master.php --user=sysadmin` makes one master. Then log out and in again.

Updates: `cd /opt/flowork && git pull && docker compose up -d --build`. phpMyAdmin: `ssh -L 8081:127.0.0.1:8081 user@vps`, then open `http://localhost:8081`.

## 6. Updates: every installation updates itself

Each Flowork installation (the cPanel site, a company's own server, a floor PC) fetches signed releases from GitHub by itself and installs them. Nothing has to connect in to the site, so firewalls and home routers are no problem, and no one has to visit it.

**Publishing a release** (from the development PC):

1. If the change adds tables or columns: `php maintenance/schema_export.php` and commit `updater/schema.json` with it.
2. Put the new version in `VERSION` (e.g. `1.0.1`), commit, push.
3. `git tag v1.0.1` then `git push origin v1.0.1`. GitHub (`.github/workflows/release.yml`) checks the PHP, builds the package, signs it and publishes the release.
   A tag with a dash (`v1.1.0-rc.1`) is a pre-release: only sites on the **Test** channel take it. Test on such a site, then tag the release without the dash for everyone.

**What each site does** (`updater/update.php`, every 15 minutes):

- checks GitHub for a newer release on its channel, downloads it and **refuses it unless the signature matches** the Flowork release key (`updater/public_key.pem`) and every file matches its signed checksum;
- installs only in its **update hours** (default 01:00–05:00) and when nobody has done anything for 15 minutes, or when a master user chooses **Install now** (System Updates page). In **approve** mode it downloads and waits for that;
- backs up every file it replaces to `backups/updates/`, shows visitors an "updating" page for the few seconds files are swapped, adds new tables and columns to the database (never changes or deletes data or settings), then checks the sign-in page still loads; **anything wrong puts the previous version back**, and that version is not retried until an admin asks.

Never changed by an update: `.env`, `includes/timb_key.php`, `uploads/`, `backups/`, cPanel's own files and the cPanel block in `.htaccess`, and every row in the database (company settings, rates, deductions, users, transactions).

**Turning it on at a site (once):**

- **cPanel** → Cron Jobs → *Once Per Fifteen Minutes* → command
  `/usr/local/bin/php /home/<account>/public_html/<folder>/updater/update.php >/dev/null 2>&1`
- **Windows / XAMPP** → Task Scheduler → Create Task → trigger daily, repeat every 15 minutes → action
  `C:\xampp\php\php-win.exe "C:\xampp\htdocs\<folder>\updater\update.php"`
- Then open **Admin → System Updates** as a master user once (it records the site's address for the after-install check) and choose the channel, automatic or approve, and the update hours.
- A site still on a version without the updater gets these files once by hand (or through cPanel Git): `VERSION`, `includes/updater.php`, `includes/schema_sync.php`, `includes/conn.php`, `includes/layout.php`, `system_updates.php` and the `updater/` folder. From then on it updates itself.

**The release key:** the private key signs every release. It is the GitHub secret `FLOWORK_SIGNING_KEY`, with a copy somewhere safe off the development PC (a password manager, or a USB stick kept locked away). Whoever has it can publish updates every site will install, so it never goes in the code, in chat or in email. If it is ever exposed: make a new key pair, ship the new `updater/public_key.pem` in a release signed with the old key, then switch the secret.

## 7. Still to do on this server (needs an administrator)

- **Restart Apache** (section 2, step 1).
- **Backups:** copy `BACKUP_PASSPHRASE` from `.env` to a safe place off this PC; point `BACKUP_COPY_DIR` at an external drive or cloud folder when one is available (today: `C:\FloworkBackups`, same disk); in Task Scheduler set `\Flowork\Nightly backup` to "Run whether user is logged on or not".
- **MySQL on this machine only:** add `bind-address=127.0.0.1` under `[mysqld]` in `C:\xampp\mysql\bin\my.ini` and restart the `mysql` service. Today port 3306 listens on the LAN (root can only log in from this PC, but the port need not be open).
- **Root password:** after the `/flowork` copy is retired, give MySQL `root` a password and remove the passwordless `pma` user (phpMyAdmin).
- **Admins:** `sysadmin` and `bright` must set up 2FA at their next login (an authenticator app on their phone).
