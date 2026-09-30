# Laravel Manager

Laravel Manager is a self-hosted web application for managing Laravel applications hosted on the same Ubuntu VPS. The product goal is one VPS, one Laravel Manager installation, many Laravel apps.

## Project status

RUN 01 through RUN 20 are complete. A first-run setup guides the administrator through server defaults, read-only environment checks, optional GitHub connection, and manual wildcard DNS confirmation before opening Apps. Settings hold this server's identity, Laravel app defaults, and GitHub connection. The protected Server page shows local environment details and read-only software and service checks. Create App lets the administrator choose PHP 8.2, 8.3, or 8.4 and MySQL or PostgreSQL for each app. Protected Project actions configure its PHP-FPM virtual host, create and verify a dedicated database, deploy manually or after a matching GitHub push, and enable HTTPS with Let's Encrypt. The authenticated header offers System, Light, and Dark themes; an explicit choice is saved in the current browser.

Laravel Manager itself stays on PHP 8.3 and uses MySQL in production. The server installer installs the supported app PHP-FPM runtimes and database services up front. Create App shows unavailable choices as disabled with the missing requirement; it never installs server packages from a web request.

## Stack

- Laravel 13.33
- Livewire 4.4, Alpine.js, Blade, and Tailwind CSS 4
- SQLite for local development
- Pest 4.7 for automated tests
- April UI 1.3 as the primary UI component and template reference

## Local requirements

- PHP 8.3 or newer with required Laravel extensions
- Composer
- Node.js and npm

## Production installation

The production installer targets a clean Ubuntu 24.04 LTS VPS on amd64 or arm64. Ubuntu's archive supplies PHP 8.3; the installer adds the [Ondřej Surý PHP PPA](https://launchpad.net/~ondrej/+archive/ubuntu/php) for PHP 8.2 and 8.4, then installs all three FPM runtimes with MySQL/PostgreSQL drivers. It also installs Apache, MySQL, PostgreSQL, Git, Node.js 24, Composer, Certbot, Laravel Manager, its MySQL database, the restricted helpers, and a systemd queue worker. The app database services and runtimes are ready before app creation; Laravel Manager itself remains pinned to PHP 8.3 and MySQL. It serves Laravel Manager on port 8080 and prompts for the first administrator email and password.

The installer script and project are published at `PhillipNobel/laravel-manager`:

```bash
curl -fsSL https://raw.githubusercontent.com/PhillipNobel/laravel-manager/main/scripts/install.sh \
  | sudo env LARAVEL_MANAGER_REPOSITORY=https://github.com/PhillipNobel/laravel-manager.git bash -s --
```

The installer detects the VPS public IPv4 address and sets Laravel's `APP_URL` to `http://PUBLIC_IP:8080`, so page assets and Livewire requests use the browser-reachable address. If UFW is already active, it adds an allow rule for TCP 8080. On Ubuntu images that use the supported Oracle firewall format, it adds TCP 8080 to `/etc/iptables/rules.v4`, preserves existing rules, and enables `netfilter-persistent`. If public IPv4 detection is unavailable, or the manager uses a domain or another address, set `MANAGER_URL` to the browser-reachable URL in the `sudo env` installation command. Use an HTTPS URL only when a TLS reverse proxy for Laravel Manager is already configured. After the installer creates the administrator, sign in to complete first-run setup before opening Apps or Settings.

For a non-mutating local check, run:

```bash
LARAVEL_MANAGER_REPOSITORY=https://github.com/PhillipNobel/laravel-manager.git bash scripts/install.sh --dry-run
```

The installer changes the host and does not roll back completed package or service changes if a later step fails. It refuses to overwrite existing Laravel Manager paths. Review failures on a disposable clean VPS before retrying. It adds only the TCP 8080 allow rule when UFW is already active, or the supported rule in Oracle's persistent iptables file; it does not change SSH or cloud/provider firewall rules, DNS, or unrelated services. Configure the applications domain and other server values in Settings after login. Commit `9210f04` passed a clean Ubuntu 24.04 arm64 Multipass installation test, including login, manager pages at desktop/mobile sizes, all four services, the database, and both sudoers checks.

Port 8080 serves Laravel Manager over plain HTTP. After installation, allow inbound TCP 8080 in the VPS subnet/security list; the installer handles supported local UFW and Oracle Ubuntu firewall formats. Restrict the cloud rule's source to your trusted IP where possible, because HTTP does not encrypt passwords or session cookies. Ports 80 and 443 serve managed application sites and certificates. The Manager itself does not provision TLS.

### VPS DNS, firewall, and first access

The cloud firewall and the Ubuntu host firewall are separate. A request can reach Apache only when both allow it. The installer can add TCP 8080 to supported host firewall configurations; it does not edit Oracle Cloud security lists/NSGs or DNS records. In OCI, add an ingress rule to the instance's subnet security list or attached network security group for TCP 8080, preferably limited to your current public IP while using the initial HTTP address. Oracle documents [security lists](https://docs.oracle.com/en-us/iaas/Content/Network/Concepts/securitylists.htm) and [NSG security rules](https://docs.oracle.com/en-us/iaas/Content/Network/Concepts/manage-nsg-security-rules.htm).

For a Manager hostname, create a Cloudflare `A` record such as `manager.example.com` pointing to the VPS public IPv4. Keep it **DNS only** (gray cloud) while checking direct origin access. Add an `AAAA` record only if the VPS has working public IPv6; an incorrect AAAA record can make clients attempt an unreachable IPv6 address. Cloudflare explains [A/AAAA record behavior](https://developers.cloudflare.com/dns/manage-dns-records/reference/dns-record-types/) and the difference between [DNS-only and proxied records](https://developers.cloudflare.com/dns/proxy-status/). If an app wildcard is used, create `*.apps.example.com` pointing to the same public IPv4; Laravel Manager does not create DNS records.

Allow inbound TCP 80 and 443 in the provider firewall when serving managed apps or requesting Let's Encrypt HTTP-01 certificates. Keep SSH (TCP 22) restricted to trusted addresses. For the initial Manager login, allow TCP 8080 only from your IP when possible. The installer adds a host rule when it recognizes active UFW or the supported Oracle Ubuntu iptables file, but a provider rule is still needed. If `ufw` is not installed on an Oracle image, inspect the active iptables/nftables rules instead; `ufw: command not found` alone does not indicate that the host has no firewall.

Check the Manager listener and local response on the VPS:

```bash
sudo ss -lntp | grep ':8080' || true
curl -sS -o /dev/null -w 'HTTP %{http_code}\n' http://127.0.0.1:8080/login
sudo iptables -S INPUT | grep -- '--dport 8080' || echo HOST_RULE_MISSING
sudo grep -nE -- '--dport (22|80|443|8080)' /etc/iptables/rules.v4 2>/dev/null || true
```

Then test from your computer using the public address:

```bash
curl -v --connect-timeout 5 http://PUBLIC_IP:8080/login
```

An HTTP 200 from `127.0.0.1` proves Apache and Laravel are responding locally. If the outside request is refused or times out, check the host INPUT chain and the OCI subnet/NSG ingress rule independently. On the Oracle Ubuntu firewall format used by this installer, an allow rule must come before the final reject. Back up the persistent file, then insert the following rule after the SSH allow rule and before the final reject:

```text
-A INPUT -p tcp -m state --state NEW -m tcp --dport 8080 -j ACCEPT
```

Validate and apply the complete file, then save the active rules when `netfilter-persistent` is installed:

```bash
sudo cp -a /etc/iptables/rules.v4 /etc/iptables/rules.v4.bak
sudoedit /etc/iptables/rules.v4
sudo iptables-restore --test < /etc/iptables/rules.v4
sudo iptables-restore < /etc/iptables/rules.v4
if command -v netfilter-persistent >/dev/null; then sudo netfilter-persistent save; fi
```

Keep the SSH rule and all existing cloud-image service rules; do not flush or replace the ruleset.

If the login page returns 200 but its assets or Livewire requests point to a private address such as `10.x.x.x`, set `APP_URL` to the same browser-reachable public IP and port or the Manager HTTPS hostname. The installer now detects the public IPv4 and uses it by default; set `MANAGER_URL` during installation when using a domain or another public URL. On an existing installation, edit the environment file as root and run Artisan as the web user with the absolute project path; the source directory is intentionally not readable by the `ubuntu` shell user:

```bash
sudoedit /opt/laravel-manager/.env
# Set APP_URL=http://PUBLIC_IP:8080 or APP_URL=https://manager.example.com
sudo -u www-data /usr/bin/php8.3 /opt/laravel-manager/artisan optimize
sudo systemctl reload php8.3-fpm
```

Do not run `composer update` on the VPS to fix an `APP_URL` change. When browser access works, test login and the Livewire interactions before closing port 8080 at the provider firewall.

### HTTPS for the Laravel Manager panel

The installer exposes the Manager on HTTP port 8080. For regular use, put Apache TLS on the Manager hostname and reverse-proxy it to `http://127.0.0.1:8080`. This proxy is configured by the server administrator; Laravel Manager's **Enable HTTPS** action applies to managed Laravel apps, not the Manager panel. First point the Manager `A` record to this server and allow TCP 80/443 in the provider firewall. Before requesting a certificate, Apache must have an enabled HTTP virtual host whose `ServerName` matches the Manager hostname, and the hostname must resolve to this VPS. Follow the [Certbot Apache instructions](https://certbot.eff.org/instructions), then check the generated `*:443` virtual host and make sure it contains a reverse proxy to the local port 8080. A typical TLS virtual host includes:

```apache
<VirtualHost *:443>
    ServerName manager.example.com
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/manager.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/manager.example.com/privkey.pem

    ProxyRequests Off
    ProxyPreserveHost Off
    ProxyPass / http://127.0.0.1:8080/
    ProxyPassReverse / http://127.0.0.1:8080/
    RequestHeader set X-Forwarded-Host "manager.example.com"
    RequestHeader set X-Forwarded-Proto "https"
    RequestHeader set X-Forwarded-Port "443"
</VirtualHost>
```

Enable Apache's `proxy`, `proxy_http`, `headers`, and `ssl` modules if needed. Keep `ProxyRequests` off; Apache's [reverse proxy documentation](https://httpd.apache.org/docs/2.4/mod/mod_proxy.html) describes the `ProxyPass` directives. Laravel Manager trusts forwarded headers only from `127.0.0.1`, so keep the proxy upstream on loopback as shown. Configure the `*:80` vhost to redirect ordinary Manager requests to HTTPS while leaving Certbot's HTTP-01 challenge available. Check the configuration with `sudo apache2ctl configtest`, reload Apache, and verify from your computer:

```bash
curl -I https://manager.example.com/login
curl -I http://manager.example.com/login
```

The expected responses are HTTPS 200 and HTTP 301. Set `APP_URL=https://manager.example.com` and `SESSION_SECURE_COOKIE=true` in `/opt/laravel-manager/.env`, rebuild Laravel's cached configuration as `www-data` using the command above, and confirm login, CSS/JS, secure session cookies, and Livewire updates work through HTTPS. Once verified, remove public TCP 8080 from the OCI security list/NSG and host firewall if it was opened solely for initial setup. Keep the local Apache listener on 8080 for the reverse proxy.

If the hostname is orange-cloud proxied in Cloudflare, set the zone SSL/TLS mode to **Full (strict)** after Apache has a valid, unexpired origin certificate for that hostname. This mode requires HTTPS at the origin and a certificate valid for the requested host; see Cloudflare's [Full (strict) requirements](https://developers.cloudflare.com/ssl/origin-configuration/ssl-modes/full-strict/). With DNS-only gray cloud, clients connect directly to the VPS certificate.

### Updating Laravel Manager

Check the installed commit with `sudo laravel-manager version`, then update from its configured HTTPS GitHub origin and checked-out branch:

```bash
sudo laravel-manager update
```

Installations created before the updater was added need a one-time command bootstrap. Download the updater script, install it as root, then run the update command:

```bash
updater_tmp="$(mktemp)"
curl -fsSL https://raw.githubusercontent.com/PhillipNobel/laravel-manager/main/scripts/laravel-manager -o "$updater_tmp"
sudo install -o root -g root -m 0755 "$updater_tmp" /usr/local/bin/laravel-manager
rm -f "$updater_tmp"
sudo laravel-manager update
```

The updater refuses local source changes and non-fast-forward updates. It briefly places the Manager in maintenance mode and stops Apache, active PHP-FPM services, and the queue while it updates Composer dependencies, builds frontend assets, runs migrations, rebuilds Laravel caches, and restarts services. Composer, npm, Artisan, and migrations run as `www-data`; the updater itself is root-owned. It checks the local login page when services return. Do not interrupt an update. If it fails, review the command output and Laravel/Apache logs, resolve the reported issue, and rerun the same update command. Database migrations are not rolled back automatically.

### Update from the panel

Open **Settings → Manager updates → Check for updates**. Review the installed version and available commit, select **Update now**, and confirm the brief interruption. The panel and managed sites can become unavailable while shared Apache/PHP-FPM services restart. The browser reconnects automatically and displays the result and a bounded list of update steps. Close or refresh the browser without stopping the independent systemd update service.

An update is refused while app provisioning, database/certificate setup, or deployments are queued/running. Finish those operations first. CLI and panel updates share one lock, and new infrastructure work is blocked once an update starts. The update follows the installed origin/branch and checks GitHub again at execution time; the available commit can advance between checking and confirming.

New installations include the panel update service. Servers installed before RUN 19 need this one-time bootstrap, performed when no application operation is running:

```bash
sudo laravel-manager update
sudo laravel-manager enable-panel-updates
```

The first command updates the source and CLI. The second installs the root-owned bridge, independent systemd unit, temporary lock configuration, and exact sudo rules. Later updates refresh those files automatically. If the updater command itself is missing, use the download bootstrap above first. Do not reinstall the Manager or recreate its database.

Local development and installations without the bridge show panel updates disabled. If a check fails, try again later. If an update fails, inspect the server and run `sudo laravel-manager update` to diagnose/resume; the CLI remains available even when the panel cannot boot. An interrupted update keeps a root-owned recovery marker. Migrations have no automatic rollback: keep a current database backup before updating a production server.

Update status is stored outside the checkout/database in root-owned `/var/lib/laravel-manager/updates/status.json`. The panel exposes only fixed phases, safe commit identifiers, timestamps, and bounded step history. Raw Composer/Git/environment diagnostics are not exposed by HTTP. The service has no dependency on Apache or the Manager queue; `www-data` can invoke only the exact bridge `status`, `check`, and `start` operations, never its internal `run` or `progress` actions or the general root updater. This remains a trusted-repository server: all apps share `www-data`; the bridge does not provide per-project isolation.

Run each project RUN's server behavior checks on the existing disposable Ubuntu 24.04 Multipass VM `laravel-manager-run11`. Inspect its current state first; do not reset or reinstall it without explicit authorization. Record the VM commands and results in `RUNS.md`.

## Local installation

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install
npm run build
```

Set `DB_CONNECTION=sqlite` and local administrator credentials in `.env` before seeding. The application uses `database/database.sqlite` by default.

```bash
touch database/database.sqlite
php artisan migrate --seed
```

`DatabaseSeeder` calls `AdminUserSeeder`. Set `LOCAL_ADMIN_EMAIL` and `LOCAL_ADMIN_PASSWORD` in `.env`; the seeder refuses to run if either is empty.

## Run locally

Run each command in its own terminal:

```bash
php artisan serve
```

```bash
npm run dev
```

```bash
php artisan queue:work --timeout=3600 --tries=1
```

Open `http://127.0.0.1:8000`.

## Tests

```bash
php artisan test
```

## Build frontend assets

```bash
npm run build
```

## First administrator

For local development, set `LOCAL_ADMIN_EMAIL` and `LOCAL_ADMIN_PASSWORD` in `.env`, then run `php artisan db:seed --class=AdminUserSeeder`. This project has no public registration route. Do not reuse development credentials in a shared environment.

## Security model

Laravel Manager assumes one trusted administrator and trusted application repositories. OAuth access tokens are encrypted in the database and hidden from model serialization. Application database credentials stay in each application's mode-`0600` `.env`; deployment output filters credentials and sensitive environment values. Webhooks require an HMAC-SHA256 signature over the exact raw body and reject payloads larger than 25 MiB. State-changing browser routes require authentication and Laravel's CSRF protection; only the signed GitHub webhook route is excluded.

The current installer runs Manager PHP-FPM, its queue worker, managed PHP-FPM applications, and deployment scripts as `www-data`. This is not a per-application isolation boundary: trusted project code can access files available to that account, including sibling application environments and Manager runtime/configuration files, and can invoke the same narrowly allowlisted sudo helpers. Do not connect repositories maintained by untrusted users or use this installation as a multi-tenant host. Separate Manager and per-project operating-system users and PHP-FPM pools are required before supporting different trust levels.

Deployment runs Composer, npm lifecycle/build scripts, and Laravel Artisan code from the selected repository. These operations use fixed commands and run unprivileged, but the repository's code still executes with the shared `www-data` identity. The sudo helpers accept only fixed operations and validated project identifiers; Laravel Manager exposes no general-purpose terminal.

## Server configuration

First-run setup saves the base applications domain, public IP, applications directory, and default PHP version. It runs read-only availability checks for the server software and lets the administrator confirm that wildcard DNS points to the VPS. GitHub connection is optional during setup. Finish setup to open Apps; server values can be changed later in Settings.

The **Server** page shows configured values alongside the detected operating system, PHP runtime, and availability/version checks for Apache, PHP-FPM 8.2/8.3/8.4, MySQL, PostgreSQL, Git, Composer, Node.js, and Certbot.

Software checks run fixed local commands with a two-second timeout and confirm the database/FPM services are active. They do not make network requests or change the server. A missing or inactive requirement is reported as **Not detected**.

## GitHub connection

Laravel Manager uses a GitHub OAuth App for the single administrator's GitHub account. Configure DNS and HTTPS for the Manager hostname first so GitHub can send the browser back to a stable public HTTPS address. In GitHub **Settings → Developer settings → OAuth Apps**, create an OAuth App, use the Manager address as its homepage URL, and register this exact callback/redirect URI (GitHub's form label can vary):

```text
https://YOUR-MANAGER-HOST/settings/github/callback
```

Add the OAuth App credentials to the Laravel Manager server environment:

```dotenv
GITHUB_CLIENT_ID=your-oauth-app-client-id
GITHUB_CLIENT_SECRET=your-oauth-app-client-secret
GITHUB_REDIRECT_URI=https://YOUR-MANAGER-HOST/settings/github/callback
```

The redirect URI must exactly match the callback URL registered in the OAuth App. During local development, run `php artisan config:clear` after changing environment values. On an installed VPS, use the `www-data` Artisan commands below so cached configuration is rebuilt with the correct ownership.

On an installed VPS, add the Client ID and Client Secret to `/opt/laravel-manager/.env` without sharing or committing the secret, then rebuild configuration as the application account:

```bash
sudoedit /opt/laravel-manager/.env
sudo -u www-data /usr/bin/php8.3 /opt/laravel-manager/artisan optimize
sudo systemctl reload php8.3-fpm
```

Use the same URL and callback in the OAuth App and `.env`, including `https://`, hostname, path, and trailing-slash choice. GitHub OAuth callback URLs must match what the app sends; do not use wildcard callback hosts. Sign in to the Manager, open **Settings → GitHub → Connect GitHub**, approve the requested access, and confirm the account and repository list appear. The OAuth flow uses a one-time `state` value and PKCE. GitHub's documentation covers [OAuth App authorization and callback URLs](https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps).

The authorization asks for the `repo` scope so the manager can list private repositories as well as public repositories. GitHub's OAuth `repo` scope grants broad access to private repositories for that account; review the [scope description](https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/scopes-for-oauth-apps) and consider a dedicated GitHub account that can access only the repositories it should manage. Laravel Manager stores the OAuth token encrypted using `APP_KEY` and never displays it. Keep `APP_KEY` stable while a GitHub connection is stored; changing it requires reconnecting GitHub.

Settings lists up to 100 repositories sorted by recent activity, with visibility and default branch. **Disconnect** revokes the OAuth token at GitHub before deleting the local connection. After provisioning an app, Laravel Manager attempts to configure a push webhook using this connection. The account must have repository administrator access. Existing apps can use Configure webhook on their Project page.

## Creating an application

Connect GitHub in **Settings**, then open **Apps → Create App**. Enter an application name and subdomain, then choose either an existing repository or **Create a new private repository**. New repository names come from the subdomain and appear under the connected GitHub account; they start on `main`. Laravel Manager creates a Laravel starter compatible with the selected PHP version, commits it, creates a private GitHub repository, pushes `main`, then provisions the project from that repository. PHP 8.2 uses Laravel 12; PHP 8.3 and 8.4 use Laravel 13. Composer does not install dependencies or run scripts during app creation; deployment installs dependencies later. If GitHub created the repository but the push failed, the failed Project retains the repository link so the failure can be investigated without losing the remote repository.

For an existing repository, choose its branch, PHP version, and database engine. The branch defaults to the selected repository's default branch. The domain is generated from the configured base applications domain. PHP and database options that are missing a server prerequisite remain visible but disabled, with the needed package or service shown beside the control. Laravel Manager checks those requirements again when saving, so a browser request cannot bypass the disabled state.

Laravel Manager rechecks repository access, validates the branch, then clones the selected branch into:

```text
{applications directory}/{subdomain}
```

It refuses to overwrite an existing path. The configured applications directory must be writable by the operating-system user running Laravel Manager. New application and runtime directories receive restrictive, explicit permissions; generated `.env` files are owner-only (`0600`). The production installer creates `/var/www/apps` for `www-data` and configures PHP-FPM access.

For a repository with `artisan`, `composer.json`, and `.env.example`, Laravel Manager creates `.env` with a fresh application key, `APP_ENV=production`, `APP_DEBUG=false`, and the generated application URL. The GitHub token is supplied only through temporary Git process configuration and is not stored in the repository, project record, or provisioning log. For a new repository, Composer only fetches the official Laravel starter; dependency installation, plugins, scripts, Artisan, npm, and repository-provided code do not run during app creation. Existing repositories are cloned without running their code. Use **Deploy now** after database setup to install dependencies and run application commands.

The Project page shows the current status, path, and bounded provisioning log. If creation fails, fix the repository or server path and choose an unused subdomain for another attempt; the failed project's domain remains recorded.

## Apache domains

RUN 05 adds an Apache domain action to provisioned Project pages. Laravel Manager maps `{domain}` to `{application path}/public`; `.env` and the rest of the project remain outside DocumentRoot. Each virtual host selects the project's PHP-FPM Unix socket. The action validates the site, enables its Apache configuration, and requests a graceful reload. **Check status** checks the enabled site, Apache configuration syntax, and Apache service state. This does not test external DNS or HTTPS.

Wildcard DNS must already point at this VPS, for example `*.apps.example.com`. Laravel Manager does not create or edit Cloudflare records. Configure the base applications domain and applications directory in Settings, then ensure the root-owned helper configuration matches those values. The production installer installs the helper and its sudo rule on a clean server. The helper enables Apache `mod_rewrite`, which Laravel's public `.htaccess` needs for application routes.

For an existing server or manual repair, install the helper on Ubuntu 24.04 after Apache is installed:

```bash
sudo install -d -o root -g root -m 0755 /etc/laravel-manager
sudo install -o root -g root -m 0755 scripts/laravel-manager-apache /usr/local/sbin/laravel-manager-apache
sudo install -o root -g root -m 0644 scripts/laravel-manager-apache.json.example /etc/laravel-manager/apache.json
sudo install -o root -g root -m 0440 scripts/laravel-manager-apache.sudoers /etc/sudoers.d/laravel-manager-apache
sudo visudo -cf /etc/sudoers.d/laravel-manager-apache
```

Edit `/etc/laravel-manager/apache.json` as root so `base_domain` and `applications_directory` exactly match Settings. Run Laravel Manager's PHP-FPM and queue workers as an unprivileged account (`www-data` by default); only the standalone helper runs as root. The helper must remain owned by root and not writable by the Laravel Manager account. The supplied sudoers rule permits the default Ubuntu web account, `www-data`, to invoke only the helper's fixed `enable`, `status`, `ssl-enable`, and `ssl-status` forms with a validated slug and PHP 8.2/8.3/8.4 value. If Laravel Manager uses another unprivileged account, update the rule to that exact account. Do not grant sudo access to a shell, Apache commands, `tee`, or arbitrary arguments to other programs. The helper writes only slug-derived site files, refuses to replace different existing sites, validates Apache configuration before enabling changes with a graceful reload, and does not execute shell commands.

Local development does not install or invoke the production helper as root. Laravel always uses non-interactive `sudo -n`; the helper rejects calls unless it runs as root. Tests fake every sudo/Apache process call.

## HTTPS certificates

RUN 09 adds an **Enable HTTPS** action to an active Project with a configured Apache domain. DNS for the app hostname must resolve to this VPS, and inbound ports 80 and 443 must be reachable. The administrator email on the signed-in Laravel Manager account is used as the Let's Encrypt account contact. The queued operation requests a certificate with Certbot's Apache authenticator, writes a managed TLS virtual host, redirects HTTP to HTTPS, and leaves `/.well-known/acme-challenge/` available for HTTP-01 validation. **Check HTTPS status** verifies the certificate hostname and expiry. The page shows the expiry date and safe retry guidance.

The production installer installs Certbot and its Apache plugin. On an existing server maintained manually, install them before enabling HTTPS:

```bash
sudo apt update
sudo apt install certbot python3-certbot-apache
```

Certbot packages provide an automatic renewal schedule through cron or a systemd timer. Confirm the package's scheduler is present and test renewal with `sudo certbot renew --dry-run`. Laravel Manager installs a fixed deploy hook at `/etc/letsencrypt/renewal-hooks/deploy/laravel-manager-apache-reload`; after a successful renewal it runs only `systemctl reload apache2`. This RUN does not install packages or create Cloudflare DNS records.

Let's Encrypt's HTTP-01 check needs the application's public hostname to resolve to this VPS and inbound TCP 80 to reach Apache; see the [HTTP-01 challenge requirements](https://letsencrypt.org/docs/challenge-types/#http-01-challenge). Ensure the hostname's A/AAAA records point only to working public addresses. Allow TCP 443 for visitors after the HTTPS virtual host is enabled. The Manager checks the certificate hostname and expiry; it does not validate Cloudflare DNS settings.

After deploying an update, refresh the root-owned helper and its narrow sudo rule using the Apache installation commands above, then validate the rule with `sudo visudo -cf /etc/sudoers.d/laravel-manager-apache`. The helper does not run Certbot from a web request: Laravel queues the operation, and the queue worker calls only the allowlisted helper. Tests fake that process call and render Apache templates without contacting Let's Encrypt or modifying a server.

## Application databases

Create App stores MySQL or PostgreSQL on each Project. Provisioning uses a separate database and account for the selected engine; PostgreSQL's account owns only its application's database and receives no server-level creation or role privileges. Laravel Manager's production database remains MySQL.

The production installer installs the database helper and its narrow sudo rule. On an existing server maintained manually, install them after installing and starting the selected database service and the matching PHP database driver:

```bash
sudo install -o root -g root -m 0755 scripts/laravel-manager-database /usr/local/sbin/laravel-manager-database
sudo install -d -o root -g root -m 0755 /etc/laravel-manager
sudo install -o root -g root -m 0644 scripts/laravel-manager-database.json.example /etc/laravel-manager/database.json
sudo install -o root -g root -m 0440 scripts/laravel-manager-database.sudoers /etc/sudoers.d/laravel-manager-database
sudo visudo -cf /etc/sudoers.d/laravel-manager-database
```

Edit `/etc/laravel-manager/database.json` as root so `applications_directory` exactly matches **Settings → Applications directory**. For MySQL, the helper connects through its Unix socket as operating-system root; configure MySQL's local socket authentication for MySQL `root`. For PostgreSQL, the helper uses `runuser` to connect as the local `postgres` operating-system account. Laravel Manager itself stays unprivileged; by default the sudo rule allows only `www-data` to invoke this helper with a validated project slug and a fixed `mysql` or `pgsql` engine value. If its service uses another account, change the rule to that exact account.

On a provisioned Project page, choose **Create database**. The helper first verifies that Git ignores `.env`, then creates a slug-derived database and account for the selected engine, grants access only to that database, writes the connection settings to the application's `.env` file, and tests the app account before reporting success. MySQL uses its local socket; PostgreSQL verifies the password over the local TCP connection. It clears any repository `DB_URL`. The `.env` file remains owner-only (`0600`) and keeps its existing owner. The password is randomly generated and remains only in that file; Laravel Manager does not store it on the Project record or show it in the interface.

The helper refuses to take over a matching database or user/role that already exists. It attempts to restore `.env` and remove any resources it created when setup fails. If cleanup itself fails, or an interrupted helper leaves a resource behind, an administrator must review the selected database service before retrying. Local development continues to use SQLite; tests fake the helper and never connect to MySQL or PostgreSQL.

## Manual deployments

On a provisioned Project page, choose **Deploy now** to queue a deployment of the configured branch. Laravel Manager uses the database queue. Run this worker locally; on a production installation, RUN 11 configures a persistent systemd worker as `www-data`:

```bash
php artisan queue:work --timeout=3600 --tries=1
```

The queue's `retry_after` defaults to 3660 seconds, longer than the deployment job timeout. The job prevents a second pending or running deployment for the same app, records status, timestamps, commit hash/message, and bounded output, and marks failures for review. The page refreshes while a deployment is pending or running.

Each deployment fetches the latest configured GitHub branch, verifies the repository and `.env` handling, checks out the commit, runs Composer under the Project's selected PHP binary, runs `npm ci` (or `npm install` without a lockfile) when `package.json` exists, and runs `npm run build` when that script exists. It then uses that PHP binary for Laravel cache, migration, optimization, and queue restart commands. Every operating-system process uses Laravel's Process API with a fixed argument array and a bounded timeout. The GitHub token is passed only through temporary Git environment configuration; deployment output redacts it and values from secret `.env` keys.

Deployments require an active application and database plus a connected GitHub account. They update the application's checkout in place. When the Apache domain is active, Laravel Manager checks the app with a bounded `GET http://127.0.0.1/` request and the app's domain in the `Host` header. Redirects are not followed, so this check does not depend on public DNS or HTTPS. A connection failure or HTTP 5xx marks deployment failed; an HTTP response below 500, including a redirect or 404, confirms that the app responded. The health check is skipped until Apache reports the domain active.

Use **Deploy now** to retry a failed deployment; it fetches the latest configured branch again. Deployments remain in place, without rollback or atomic release directories. Reverting application code alone cannot safely undo database migrations, and release directories would add complexity to the current deployment flow. Pest fakes every process and HTTP request; tests never deploy to a real application or server.

## Automatic GitHub deployments

Use **Project → Configure webhook** for automatic configuration; see the setup section below for requirements and verification.

### Manual hook setup (optional)

Set a dedicated webhook secret in the Laravel Manager server environment. Generate one with `openssl rand -hex 32`, then run `php artisan config:clear` after changing it:

```dotenv
GITHUB_WEBHOOK_SECRET=replace-with-a-long-random-secret
```

In the GitHub repository, add a webhook with this payload URL, content type `application/json`, the same secret, and the **push** event:

```text
https://YOUR-MANAGER-HOST/webhooks/github
```

Laravel Manager checks `X-Hub-Signature-256` against the exact raw request body. A signed push queues each ready project configured for the payload's exact repository and branch. Other events, branches, deleted branches, and unmatched repositories are acknowledged without deployment. A unique delivery ID and SHA-256 body fingerprint prevent duplicate or replayed bodies from queueing twice, even if the unsigned delivery header changes. Keep the Laravel database queue worker running as shown above.

Signed delivery records contain the GitHub delivery ID, body fingerprint, event, repository, branch ref, outcome, and number of deployments queued. The request body and signature are not stored. Invalid signatures are rejected without creating a delivery record.

RUN 20 configures repository webhooks through the GitHub API; the manual setup above remains available for installations with an explicitly configured signing secret. RUN 10 adds a post-deployment health check for active Apache domains. Retry remains a manual **Deploy now** action; automatic retries and rollback are not enabled.

### Automatic webhook setup and local development

Set **Settings → Manager URL** to your public HTTPS origin, for example `https://manager.philcode.dev.br` (no path or port). The certificate must be valid, port 443 reachable, and the connected GitHub account must have administrator access to the repository. The existing OAuth `repo` scope is reused; no second integration is required.

Create App attempts to configure the hook after successful provisioning. Existing apps use **Configure webhook** on their detail page. Manager configures an active push-only JSON hook with TLS verification. A compatible hook is reused; conflicting or unrelated hooks are preserved. **Check connection / retry** rechecks configuration and requests another ping. Webhook failure preserves the repository and provisioned app.

**Configured** means GitHub accepted the configuration. **Verified** means a correctly signed ping for that repository and hook reached Manager. A ping never deploys. If verification is pending, check the public Manager URL, DNS, valid certificate, subnet/host firewall port 443, and GitHub → Repository → Settings → Webhooks → Recent deliveries. A locally simulated ping is not evidence of public GitHub delivery.

An existing `GITHUB_WEBHOOK_SECRET` remains authoritative. If it is absent, Manager generates one persistent secret encrypted in `app_settings`, independent of the OAuth connection. Keep the Manager database and APP_KEY in backups. No secret appears in the UI or clone commands. Do not change the configured secret without updating existing hooks; retry reuses the current secret. For automatic setup, you do not need to generate or paste a secret manually.

The Project page's **Develop locally** section provides selectable/copyable instructions: clone the saved branch, install Composer/npm dependencies, prepare a local `.env`, initialize SQLite, and start Laravel and Vite in separate terminals. Use PHP matching the app (8.2 starters use Laravel 12; 8.3/8.4 starters use Laravel 13), Git, Composer, Node/npm and PDO SQLite on your computer. Customized repositories may need additional setup described in their linked README.

For local SQLite, set `APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=http://localhost:8000`, `DB_CONNECTION=sqlite`, and `QUEUE_CONNECTION=sync`. Remove `DB_URL`, `DB_DATABASE`, `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`, and `DB_SOCKET` to use Laravel's default `database/database.sqlite`. Copy `.env.example` only when `.env` does not already exist; never copy server credentials. Generate the key, create the SQLite file and run migrations once during initial setup. Confirm `.env` is ignored by Git. On later days, start Laravel/Vite, develop, commit and push the configured branch.

Before relying on pushes, finish the application's database, Apache domain, HTTPS and initial **Deploy now** steps using the existing controls. Keep the server queue worker running. Only matching signed branch pushes queue deployments; inspect deployment history for success or failure. Webhook configuration does not provision these requirements automatically.

## Roadmap

See [RUNS.md](RUNS.md). RUN 01 through RUN 20 are complete. Do not begin another RUN until explicitly requested.
