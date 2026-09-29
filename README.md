# Laravel Manager

Laravel Manager is a self-hosted web application for managing Laravel applications hosted on the same Ubuntu VPS. The product goal is one VPS, one Laravel Manager installation, many Laravel apps.

## Project status

RUN 01 through RUN 10 are complete. RUN 11 — Production Installer is in progress. Settings hold this server's identity, Laravel app defaults, and GitHub connection. The protected Server page shows local environment details and read-only software checks. Create App clones a selected GitHub repository and prepares its Laravel environment. Protected Project actions configure its Apache virtual host, create and verify a dedicated MySQL database, deploy manually or after a matching GitHub push, and enable HTTPS with Let's Encrypt.

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

The production installer targets a clean Ubuntu 24.04 LTS VPS on amd64 or arm64. It installs Apache, PHP 8.3-FPM, MySQL, Git, Node.js 24, Composer, Certbot, Laravel Manager, its database, the existing restricted helpers, and a systemd queue worker. It serves Laravel Manager on port 8080 and prompts for the first administrator email and password.

The project does not have a canonical public GitHub repository URL yet. Once one is selected, replace both placeholders below with that repository. The first URL serves the installer script; the second tells it which repository to clone into `/opt/laravel-manager`:

```bash
curl -fsSL https://raw.githubusercontent.com/OWNER/REPOSITORY/main/scripts/install.sh \
  | sudo env LARAVEL_MANAGER_REPOSITORY=https://github.com/OWNER/REPOSITORY.git bash -s --
```

For a non-mutating local check, run:

```bash
LARAVEL_MANAGER_REPOSITORY=https://github.com/OWNER/REPOSITORY.git bash scripts/install.sh --dry-run
```

The installer changes the host and does not roll back completed package or service changes if a later step fails. It refuses to overwrite existing Laravel Manager paths. Review failures on a disposable clean VPS before retrying. It does not change SSH, firewall rules, DNS, or unrelated services; allow TCP ports 80, 443, and 8080 in the VPS/provider firewall. Configure the manager URL, applications domain, and other server values in Settings after login. Full installation still needs a smoke test on disposable Ubuntu 24.04 before RUN 11 is complete.

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

## Server configuration

Open **Settings** to configure the server hostname, optional public IP, base applications domain, applications directory, default PHP version for new apps, and Laravel Manager URL. The **Server** page shows those values alongside the detected operating system, PHP runtime, and availability/version checks for Apache, MySQL, Git, Composer, Node.js, and Certbot.

Software checks run fixed local version commands with a two-second timeout. They do not make network requests or change the server. A missing command is reported as **Not detected**.

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

Connect GitHub in **Settings**, then open **Apps → Create App**. Enter an application name and subdomain, select a repository, and choose a branch and PHP version. The branch defaults to the selected repository's default branch. The domain is generated from the configured base applications domain.

Laravel Manager rechecks repository access, validates the branch, then clones the selected branch into:

```text
{applications directory}/{subdomain}
```

It refuses to overwrite an existing path. The configured applications directory must be writable by the operating-system user running Laravel Manager. New application and runtime directories receive restrictive, explicit permissions; generated `.env` files are owner-only (`0600`). The production installer creates `/var/www/apps` for `www-data` and configures PHP-FPM access.

For a repository with `artisan`, `composer.json`, and `.env.example`, Laravel Manager creates `.env` with a fresh application key, `APP_ENV=production`, `APP_DEBUG=false`, and the generated application URL. The GitHub token is supplied only through temporary Git process configuration and is not stored in the repository, project record, or provisioning log. App creation does not run Composer, Artisan, npm, or repository-provided code. Use **Deploy now** after database setup to install dependencies and run application commands.

The Project page shows the current status, path, and bounded provisioning log. If creation fails, fix the repository or server path and choose an unused subdomain for another attempt; the failed project's domain remains recorded.

## Apache domains

RUN 05 adds an Apache domain action to provisioned Project pages. Laravel Manager maps `{domain}` to `{application path}/public`; `.env` and the rest of the project remain outside DocumentRoot. The action validates the site, enables its Apache configuration, and requests a graceful reload. **Check status** checks the enabled site, Apache configuration syntax, and Apache service state. This does not test external DNS or HTTPS.

Wildcard DNS must already point at this VPS, for example `*.apps.example.com`. Laravel Manager does not create or edit Cloudflare records. Configure the base applications domain and applications directory in Settings, then ensure the root-owned helper configuration matches those values. The production installer installs the helper and its sudo rule on a clean server. The helper enables Apache `mod_rewrite`, which Laravel's public `.htaccess` needs for application routes.

For an existing server or manual repair, install the helper on Ubuntu 24.04 after Apache is installed:

```bash
sudo install -d -o root -g root -m 0755 /etc/laravel-manager
sudo install -o root -g root -m 0755 scripts/laravel-manager-apache /usr/local/sbin/laravel-manager-apache
sudo install -o root -g root -m 0644 scripts/laravel-manager-apache.json.example /etc/laravel-manager/apache.json
sudo install -o root -g root -m 0440 scripts/laravel-manager-apache.sudoers /etc/sudoers.d/laravel-manager-apache
sudo visudo -cf /etc/sudoers.d/laravel-manager-apache
```

Edit `/etc/laravel-manager/apache.json` as root so `base_domain` and `applications_directory` exactly match Settings. Run Laravel Manager's PHP-FPM and queue workers as an unprivileged account (`www-data` by default); only the standalone helper runs as root. The helper must remain owned by root and not writable by the Laravel Manager account. The supplied sudoers rule permits the default Ubuntu web account, `www-data`, to invoke only the helper's fixed `enable`, `status`, `ssl-enable`, and `ssl-status` forms with validated arguments. If Laravel Manager uses another unprivileged account, update the rule to that exact account. Do not grant sudo access to a shell, Apache commands, `tee`, or arbitrary arguments to other programs. The helper writes only slug-derived site files, refuses to replace different existing sites, validates Apache configuration before enabling changes with a graceful reload, and does not execute shell commands.

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

The production installer installs the database helper and its narrow sudo rule. On an existing server maintained manually, install them after MySQL is available:

```bash
sudo install -o root -g root -m 0755 scripts/laravel-manager-database /usr/local/sbin/laravel-manager-database
sudo install -d -o root -g root -m 0755 /etc/laravel-manager
sudo install -o root -g root -m 0644 scripts/laravel-manager-database.json.example /etc/laravel-manager/database.json
sudo install -o root -g root -m 0440 scripts/laravel-manager-database.sudoers /etc/sudoers.d/laravel-manager-database
sudo visudo -cf /etc/sudoers.d/laravel-manager-database
```

Edit `/etc/laravel-manager/database.json` as root so `applications_directory` exactly matches **Settings → Applications directory**. The helper connects to the local MySQL server through its Unix socket as the operating-system root account. Configure MySQL so local socket authentication for MySQL `root` works for the helper. Laravel Manager itself stays unprivileged; by default the sudo rule allows only `www-data` to invoke this one helper with a validated project slug. If its service uses another account, change the rule to that exact account.

On a provisioned Project page, choose **Create database**. The helper first verifies that Git ignores `.env`, then creates a slug-derived database and account, grants privileges only on that database, writes the connection settings to the application's `.env` file, and tests that account through the same MySQL socket before reporting success. It clears any repository `DB_URL` and sets the local socket path so repository defaults cannot redirect the connection. The `.env` file remains owner-only (`0600`) and keeps its existing owner. The password is randomly generated and remains only in that file; Laravel Manager does not store it on the Project record or show it in the interface.

The helper refuses to take over a matching database or user that already exists. It attempts to restore `.env` and remove any resources it created when setup fails. If cleanup itself fails, or an interrupted helper leaves a resource behind, an administrator must review MySQL before retrying. Local development continues to use SQLite; tests fake the helper and never connect to MySQL.

## Manual deployments

On a provisioned Project page, choose **Deploy now** to queue a deployment of the configured branch. Laravel Manager uses the database queue. Run this worker locally; on a production installation, RUN 11 configures a persistent systemd worker as `www-data`:

```bash
php artisan queue:work --timeout=3600 --tries=1
```

The queue's `retry_after` defaults to 3660 seconds, longer than the deployment job timeout. The job prevents a second pending or running deployment for the same app, records status, timestamps, commit hash/message, and bounded output, and marks failures for review. The page refreshes while a deployment is pending or running.

Each deployment fetches the latest configured GitHub branch, verifies the repository and `.env` handling, checks out the commit, runs `composer install --no-dev`, runs `npm ci` (or `npm install` without a lockfile) when `package.json` exists, and runs `npm run build` when that script exists. It then clears Laravel caches, runs `php artisan migrate --force`, optimizes Laravel, and requests a queue worker restart. Every operating-system process uses Laravel's Process API with a fixed argument array and a bounded timeout. The GitHub token is passed only through temporary Git environment configuration; deployment output redacts it and values from secret `.env` keys.

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

Laravel Manager checks `X-Hub-Signature-256` against the exact raw request body. A signed push queues each ready project configured for the payload's exact repository and branch. Other events, branches, deleted branches, and unmatched repositories are acknowledged without deployment. Delivery IDs are unique, so a GitHub redelivery cannot queue the same event twice. Keep the Laravel database queue worker running as shown above.

Signed delivery records contain the GitHub delivery ID, event, repository, branch ref, outcome, and number of deployments queued. The request body and signature are not stored. Invalid signatures are rejected without creating a delivery record.

RUN 08 does not create repository webhooks through the GitHub API. RUN 10 adds a post-deployment health check for active Apache domains. Retry remains a manual **Deploy now** action; automatic retries and rollback are not enabled.

## Roadmap

See [RUNS.md](RUNS.md). RUN 10 is complete and RUN 11 — Production Installer is current. RUNs are strictly sequential; do not implement a later RUN until requested.
