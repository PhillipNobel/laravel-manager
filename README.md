# Laravel Manager

Laravel Manager is a self-hosted web application for managing Laravel applications hosted on the same Ubuntu VPS. The product goal is one VPS, one Laravel Manager installation, many Laravel apps.

## Project status

RUN 01 through RUN 16 are complete. A first-run setup guides the administrator through server defaults, read-only environment checks, optional GitHub connection, and manual wildcard DNS confirmation before opening Apps. Settings hold this server's identity, Laravel app defaults, and GitHub connection. The protected Server page shows local environment details and read-only software and service checks. Create App lets the administrator choose PHP 8.2, 8.3, or 8.4 and MySQL or PostgreSQL for each app. Protected Project actions configure its PHP-FPM virtual host, create and verify a dedicated database, deploy manually or after a matching GitHub push, and enable HTTPS with Let's Encrypt.

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

The installer sets Laravel's `APP_URL` to the first local IPv4 address on port 8080 and prints that address at the end. If the VPS is behind NAT or has a domain for the manager, add a browser-reachable URL such as `MANAGER_URL=http://manager.example.com:8080` to `sudo env` in the installation command. Use an HTTPS URL only when a TLS reverse proxy for Laravel Manager is already configured. After the installer creates the administrator, sign in to complete first-run setup before opening Apps or Settings.

For a non-mutating local check, run:

```bash
LARAVEL_MANAGER_REPOSITORY=https://github.com/PhillipNobel/laravel-manager.git bash scripts/install.sh --dry-run
```

The installer changes the host and does not roll back completed package or service changes if a later step fails. It refuses to overwrite existing Laravel Manager paths. Review failures on a disposable clean VPS before retrying. It does not change SSH, firewall rules, DNS, or unrelated services. Configure the applications domain and other server values in Settings after login. Commit `9210f04` passed a clean Ubuntu 24.04 arm64 Multipass installation test, including login, manager pages at desktop/mobile sizes, all four services, the database, and both sudoers checks.

Port 8080 serves Laravel Manager over plain HTTP. Do not expose it to the public internet: keep it private and use an SSH tunnel, or put a TLS reverse proxy in front of it before signing in. Ports 80 and 443 serve managed application sites and certificates. The Manager itself does not provision TLS.

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

Laravel Manager uses a GitHub OAuth App for the single administrator's GitHub account. Create an OAuth App in GitHub Developer Settings, set its homepage URL to the Laravel Manager URL, and set its authorization callback URL to:

```text
https://YOUR-MANAGER-HOST/settings/github/callback
```

Add the OAuth App credentials to the Laravel Manager server environment:

```dotenv
GITHUB_CLIENT_ID=your-oauth-app-client-id
GITHUB_CLIENT_SECRET=your-oauth-app-client-secret
GITHUB_REDIRECT_URI=https://YOUR-MANAGER-HOST/settings/github/callback
```

The redirect URI must exactly match the callback URL registered in the OAuth App. After changing environment values, run `php artisan config:clear`, then open **Settings → GitHub → Connect GitHub**.

The authorization asks for the `repo` scope so the manager can list private repositories as well as public repositories. GitHub's OAuth `repo` scope grants broad access to private repositories for that account. The flow uses one-time `state` and PKCE verification. Laravel Manager validates the account, stores the OAuth token encrypted using `APP_KEY`, and never displays it. Keep `APP_KEY` stable while a GitHub connection is stored; changing it requires reconnecting GitHub.

Settings lists up to 100 repositories sorted by recent activity, with visibility and default branch. **Disconnect** revokes the OAuth token at GitHub before deleting the local connection. The administrator configures repository webhooks in GitHub; Laravel Manager does not create them through the GitHub API.

## Creating an application

Connect GitHub in **Settings**, then open **Apps → Create App**. Enter an application name and subdomain, select a repository, and choose a branch, PHP version, and database engine. The branch defaults to the selected repository's default branch. The domain is generated from the configured base applications domain. PHP and database options that are missing a server prerequisite remain visible but disabled, with the needed package or service shown beside the control. Laravel Manager checks those requirements again when saving, so a browser request cannot bypass the disabled state.

Laravel Manager rechecks repository access, validates the branch, then clones the selected branch into:

```text
{applications directory}/{subdomain}
```

It refuses to overwrite an existing path. The configured applications directory must be writable by the operating-system user running Laravel Manager. New application and runtime directories receive restrictive, explicit permissions; generated `.env` files are owner-only (`0600`). The production installer creates `/var/www/apps` for `www-data` and configures PHP-FPM access.

For a repository with `artisan`, `composer.json`, and `.env.example`, Laravel Manager creates `.env` with a fresh application key, `APP_ENV=production`, `APP_DEBUG=false`, and the generated application URL. The GitHub token is supplied only through temporary Git process configuration and is not stored in the repository, project record, or provisioning log. App creation does not run Composer, Artisan, npm, or repository-provided code. Use **Deploy now** after database setup to install dependencies and run application commands.

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

RUN 08 does not create repository webhooks through the GitHub API. RUN 10 adds a post-deployment health check for active Apache domains. Retry remains a manual **Deploy now** action; automatic retries and rollback are not enabled.

## Roadmap

See [RUNS.md](RUNS.md). RUN 01 through RUN 16 are complete. The roadmap has no pending RUN; scope new work explicitly before starting it.
