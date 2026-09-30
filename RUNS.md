# Laravel Manager RUN Roadmap

RUNs are strictly sequential. Implement only the RUN explicitly requested. RUN 01 through RUN 19 are complete. RUN 20+ are pending.

## Required verification for every RUN

In addition to local automated checks, verify each RUN on the existing disposable Ubuntu 24.04 Multipass VM `laravel-manager-run11` (or the current Ubuntu VM explicitly selected for this project). Inspect the VM before changing it, reuse its state, and do not reset/reinstall it or overwrite its application data without explicit user authorization. Test the current work in the VM without publishing unfinished changes. For UI changes, use Chrome DevTools against the VM-hosted app where practical. Record the VM name, OS version, commands, and outcomes in that RUN. A RUN is not complete until its required VM checks pass; document blockers and leave that RUN incomplete if they cannot be performed.

## Approved product direction — per-application choices

- The installer does not ask which PHP version or database engine to use for an application. Laravel Manager itself keeps its fixed PHP 8.3 runtime and MySQL production database; local development remains SQLite.
- Create App will let the administrator choose a PHP runtime (initial options: 8.2, 8.3, or 8.4) and a database engine (MySQL or PostgreSQL) for each application. The server's default PHP setting may preselect a value, but every Project stores its own choice.
- The selected database engine is used by that application's database provisioning flow. Supported runtimes and database services must already be available on the server; never install OS packages from an HTTP request.
- This extends Create App and the MySQL-only database implementation. RUN 12 owns that extension. The installer installs all supported app runtimes and database services up front; app creation only selects already available software.

## RUN 01 — Application Foundation — Complete

### Objective

Create the local Laravel Manager application and establish the project foundation.

### Scope

- Laravel application, Livewire, Tailwind, Alpine.js, April UI foundation, SQLite development database, Pest, and login/logout authentication with public registration disabled.
- Authenticated application shell; Apps, Create App, Project detail, and Settings pages.
- Project and Deployment models, migrations, relationships, status enums, basic application settings, and isolated base-domain generation.
- Creating an app writes database records only.
- README.md, AGENTS.md, and this RUNS.md.

### Explicit exclusions

No shell execution, Apache configuration, MySQL provisioning, GitHub API/repository/webhook integration, application directories, deployment, SSL, Cloudflare API, sudo, or privileged commands.

### Acceptance criteria

- Application boots locally; login/logout work; registration is disabled.
- Apps, Create App, Project detail, and Settings work.
- Domain generation works and is tested.
- Project/Deployment relationships and statuses are represented and tested.
- Pest suite passes; frontend assets build.
- Chrome DevTools verifies login, empty/list Apps, creation, validation, domain preview, project detail, settings, navigation, responsive behavior, and console.
- README.md, AGENTS.md, and RUNS.md exist.

## RUN 02 — Server Configuration — Complete

### Objective

Introduce Laravel Manager's understanding of the local VPS environment.

### Scope

Add server settings such as server hostname, optional public IP, base applications domain, applications root directory, default PHP version, manager URL, and environment information. Add a server/environment status page. Detect or display requirements such as operating system, PHP, Apache, MySQL, Git, Composer, Node, and Certbot without modifying them.

### Explicit exclusions

Do not provision projects, modify Apache, create databases, or install packages automatically. Environment checks may only read local OS information and run fixed, read-only version/status commands.

### Acceptance criteria

- Settings persist server hostname, optional public IP, base applications domain, applications directory, default PHP version, and Manager URL with validation.
- A protected Server page displays detected operating system, PHP runtime, and configured server values.
- The Server page reports whether Apache, MySQL, Git, Composer, Node.js, and Certbot are available and shows version output where available.
- Detection uses fixed commands, bounded timeouts, and does not modify the operating system or make external network requests.
- Pest tests cover settings persistence and validation, detection results, and authentication protection.
- Chrome DevTools verifies Settings and Server on desktop and mobile, including interactions, responsive behavior, and console output.
- README.md, AGENTS.md, DESIGN.md, and RUNS.md reflect RUN 02.

## RUN 03 — GitHub Integration — Complete

### Objective

Connect Laravel Manager with GitHub using the simplest secure MVP integration.

### Scope

Use a GitHub OAuth App web flow to connect the administrator, validate the connection, obtain account and repository information, list available repositories, store credentials securely, and show connection status. Use OAuth state and PKCE, encrypt stored credentials, and request only scopes needed for this integration. Consult current GitHub documentation through Context7. Prefer simplicity; do not overengineer GitHub App infrastructure without a clear MVP benefit.

Repository creation, deployment credentials/access configuration, and webhooks remain deferred to their relevant later RUNs.

### Acceptance criteria

- GitHub connection and repository routes require administrator authentication.
- Settings shows whether the GitHub OAuth App is configured and whether an account is connected; administrators can connect or disconnect.
- OAuth uses a one-time state value and PKCE; callback failures do not save credentials.
- The OAuth token is encrypted at rest and never rendered or included in user-facing errors.
- Laravel Manager validates the connection through GitHub and lists accessible repositories with name, visibility, and default branch where available.
- HTTP integration tests fake every GitHub request and cover successful connection, invalid state, API failure, repository listing, and disconnect.
- Chrome DevTools verifies the GitHub Settings states and repository list at desktop and narrow/mobile widths, with no unexpected console errors.
- README.md, AGENTS.md, and RUNS.md document GitHub OAuth setup, scopes, and RUN 03 boundaries.

## RUN 04 — Create Real Laravel App — Complete

### Objective

Turn Create App into actual project creation.

### Desired flow

The administrator enters an application name, subdomain, an existing repository from the connected GitHub account, branch, and PHP version.

### Scope

Create the application directory under the configured applications root, clone the selected repository and branch, validate that it contains a Laravel project, initialize `.env` with a new application key and production-safe defaults, set required filesystem permissions, implement Project state transitions, and record provisioning logs.

Example path: `/var/www/apps/customer`.

Use Laravel's Process API with fixed argument arrays and bounded timeouts. Pass the GitHub OAuth credential through a temporary Git process environment setting; never include it in command arguments, remote URLs, repository config, or logs.

Do not expose unrestricted arbitrary shell execution. Do not create GitHub repositories, run `composer install`, or execute repository-provided code during provisioning. Dependency installation belongs to the deployment run.

### Acceptance criteria

- Create App requires an authenticated GitHub connection and lets the administrator select one of its accessible repositories.
- The selected repository is verified server-side before cloning; users cannot provide arbitrary clone URLs or paths.
- Project directory is derived from the configured applications root and validated subdomain; existing paths and symlink targets are never overwritten.
- Clone checks the selected branch and does not persist the OAuth token in `.git/config`, process arguments, project fields, or logs.
- A Laravel repository with `artisan`, `composer.json`, and `.env.example` receives a generated `.env`, fresh `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, and configured application URL. `.env` uses mode `0600`; runtime directories receive explicit group-writable permissions.
- Project transitions through pending and provisioning to active or failed; project detail shows sanitized provisioning logs.
- Tests fake all GitHub HTTP requests and operating-system processes; cover success, failure, validation, paths, permissions, status transitions, and token secrecy.
- Chrome DevTools verifies Create App and Project detail at desktop and narrow/mobile widths, with responsive layout and no unexpected console errors.
- README.md, AGENTS.md, and RUNS.md document the supported provisioning flow and its security boundaries.

Provisioning does not execute repository code or run Composer, Artisan, or npm. Existing applications directories are left untouched. PHP-FPM ownership and access are configured in the production installer RUN.

## RUN 05 — Apache & Domain Provisioning — Complete

### Objective

Expose created applications through Apache.

### Scope

Generate and validate Apache VirtualHosts, enable a site, reload Apache, use Laravel `/public` as DocumentRoot, map domains, and check Apache domain status. Assume wildcard DNS is already configured, e.g. `*.apps.example.com -> VPS IP`. Do not create Cloudflare DNS records unless later required.

### Acceptance criteria

- A project with a successfully provisioned Laravel directory can configure its Apache site from the authenticated Project page; an unprovisioned project cannot.
- Each project stores an independent pending, active, or failed Apache domain status and a safe, actionable message.
- VirtualHost configuration maps the project's exact domain to its `{application path}/public` directory, grants Laravel `.htaccess` overrides, and keeps project files outside DocumentRoot.
- A root-owned, standalone helper accepts only `enable` or `status` plus a validated project slug. It derives the domain and path from a root-owned settings file, rejects symlinks and unsafe paths, and invokes only fixed Apache commands with argument arrays.
- The helper enables Apache `mod_rewrite` for Laravel routes, writes only its slug-derived site file, refuses to overwrite a different existing site, runs Apache configuration validation before graceful reload, and checks enabled/config-valid status without reloading.
- Sudoers grants the web user access to that helper and its two validated operations only; Laravel Manager itself does not run as root.
- Laravel tests fake all Apache/sudo process calls and cover success, failure, status, invalid project paths, authentication, and no shell command construction. The standalone template is tested without invoking Apache.
- Chrome DevTools verifies Project domain status, actions, feedback, navigation, and responsive layout at desktop and narrow/mobile sizes; no unexpected console errors.
- README.md, AGENTS.md, and RUNS.md explain helper installation, sudoers restrictions, wildcard DNS assumptions, and the Run 05 boundary.

### Security

Laravel Manager must not run as root. Design minimum sudo permissions; never grant unrestricted sudo to the web application user.

## RUN 06 — Database Provisioning — Complete

### Objective

Create application databases automatically.

### Scope

Create a MySQL database and dedicated user per app, generate secure passwords, grant access only to that database, configure Laravel `.env`, and validate database connections. Do not expose passwords unnecessarily; handle secrets carefully.

Laravel invokes only a standalone, root-owned database helper through non-interactive sudo. The helper authenticates as the local MySQL root account through its Unix socket, accepts only a validated project slug, confirms Git ignores `.env`, refuses preexisting database/user names, and uses MySQL's local socket for the app account. It updates `.env` atomically while keeping its owner and mode `0600`; the generated password never enters the Project model, the manager database, process arguments, logs, or the UI. It attempts to roll back MySQL and `.env` changes when an operation fails.

### Acceptance criteria

- A Project records database provisioning status and safe user-facing feedback; its details page can create or retry setup only after app provisioning is active.
- MySQL database and username identifiers are deterministic, valid, and within MySQL's length limits.
- The database helper creates one schema and local account per application, grants privileges only on that schema, and never grants `GRANT OPTION` to the application account.
- The helper writes `DB_CONNECTION`, `DB_URL`, `DB_HOST`, `DB_PORT`, `DB_SOCKET`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` to `.env` with restrictive permissions and preserves the file owner.
- The helper refuses to write database credentials unless Git ignores the application's `.env` file.
- The app account must connect successfully before Laravel Manager marks the database active.
- The database password is absent from Project fields, user-facing content, process arguments, and Laravel logs.
- Sudo authorizes only the root-owned helper and one validated project slug; Laravel Manager remains unprivileged.
- Pest covers active/pending/failure states, authentication, identifier limits, helper argument validation, and secret-safe output. Tests fake MySQL helper calls and never connect to a local or production MySQL service.
- Chrome DevTools verifies the Project database status, action, failure feedback, responsive layout, and browser console at desktop and narrow/mobile widths.
- README.md, AGENTS.md, and RUNS.md explain helper installation, root-socket access expectations, secret handling, local test behavior, and the RUN 06 boundary.

## RUN 07 — Deployment Engine — Complete

### Objective

Implement manual deployments from GitHub.

### Initial deployment flow

Fetch repository; check out configured branch/commit; run Composer install; run npm install/build; migrate; optimize; restart relevant Laravel workers; finish deployment.

### Scope

Add Deployment records, statuses, output/logs, manual Deploy, a per-project concurrency lock, failure reporting, and current commit information. Use the database queue. Do not build arbitrary web terminal functionality.

### Acceptance criteria

- An authenticated administrator can manually queue a deployment for an active application with an active database and connected GitHub repository.
- Each deployment records pending, running, successful, or failed status, output, start/finish times, current commit hash, and commit message.
- A second pending/running deployment for the same project is prevented, including when duplicate jobs reach a worker.
- The queued worker fetches only the configured GitHub branch, checks out its current commit, installs Composer and applicable npm dependencies/assets, runs forced migrations, optimizes Laravel, and requests a queue worker restart.
- Every process call uses Laravel Process with fixed argument arrays and bounded timeouts. The GitHub token is supplied only through temporary process environment configuration.
- The application path, Git remote, branch, required files, `.env` ignore rule, and fetched commit are checked before running deployment steps. A commit tracking `.env` is rejected.
- Deployment output is bounded and redacts GitHub credentials and secret values from the app `.env`. Failures are recorded with safe actionable output.
- SQLite's database queue worker command and queue retry timing are documented. Tests fake every process and do not deploy real applications or connect to production services.
- Chrome DevTools verifies the deploy action, empty/history/queued/running/failed/success states, deployment output, responsive layouts, and browser console.
- README.md, AGENTS.md, and RUNS.md describe manual deployment and RUN 07 boundaries.

## RUN 08 — Automatic GitHub Deployment — Complete

### Objective

Deploy automatically after `git push`.

### Scope

Add a GitHub webhook endpoint with signature validation, repository and branch matching, deployment triggering, useful webhook logs, and duplicate-event protection. Deploy only configured branches, such as `main`. Normal workflow is `git add`, `git commit`, `git push`; no SSH deployment command should be needed.

### Acceptance criteria

- An unauthenticated GitHub POST endpoint accepts webhook JSON only when `X-Hub-Signature-256` matches an HMAC-SHA256 of the exact raw body using `GITHUB_WEBHOOK_SECRET`.
- The webhook endpoint alone is excluded from request forgery protection; manager web routes remain protected.
- The endpoint handles only `push` events, ignores deleted branches, and queues only projects whose exact GitHub repository and configured branch match the payload.
- Every ready project configured for the matching repository and branch receives a normal Run 07 deployment through the existing database queue; projects that are not ready or already deploying are skipped safely.
- Unique `X-GitHub-Delivery` and signed-body SHA-256 identifiers prevent duplicate or replayed bodies from queueing twice, including concurrent requests and changed unsigned delivery headers.
- Signed delivery metadata and outcome are recorded without storing the payload, webhook secret, or signature. Invalid signatures do not create delivery records.
- The GitHub webhook is configured manually in GitHub with JSON content, a shared secret, and the push event. Laravel Manager does not create webhook subscriptions through the API.
- Pest covers signature validation, authentication independence, repository/branch filtering, branch deletion, queue dispatch, skipped projects, and duplicate delivery IDs without running a real deployment.
- README.md, AGENTS.md, and RUNS.md document webhook configuration, secret handling, queue behavior, and the Run 08 boundary.

## RUN 09 — SSL — Complete

### Objective

Enable HTTPS for applications.

### Scope

Integrate with Let's Encrypt/Certbot using controlled operations. Support certificate creation and status, renewal compatibility, Apache HTTPS configuration, and clear failure reporting. Do not build a custom certificate authority.

### Acceptance criteria

- HTTPS can be queued only for an active, provisioned application with an active managed Apache domain and a domain matching current Settings.
- The Project page shows HTTPS status, certificate expiry, enable/retry, and status-check actions; it polls while the certificate job is running.
- A database-queued job calls only the root-owned Apache helper through fixed Laravel Process arguments and non-interactive sudo.
- The helper validates an administrator email and invokes only fixed `certbot certonly --apache` arguments. It does not accept a domain or arbitrary command from Laravel.
- The helper verifies the certificate hostname and expiry, owns the exact `*:443` site configuration, preserves HTTP-01 challenge access while redirecting other HTTP requests, validates Apache configuration, and reloads Apache gracefully.
- The helper installs a fixed Certbot deploy hook that only reloads Apache after renewal; Certbot's packaged renewal schedule remains a server prerequisite.
- Sudoers allows only the validated HTTP domain and HTTPS helper operation forms. Laravel diagnostics are logged with the account email redacted; the UI receives safe retry guidance.
- Pest covers queue/readiness/duplicate behavior, successful and failed helper responses, expiry handling, status refresh, configuration rendering, renewal hook contents, and the narrow sudo rule. Tests do not contact a production service.
- README.md, AGENTS.md, and RUNS.md explain the Certbot prerequisite, helper/sudo installation, renewal behavior, and safety boundary.

## RUN 10 — Deployment Improvements — Complete

### Objective

Make deployments safer while keeping the workflow simple.

Keep deployments safer without adding deployment infrastructure that is not needed.

### Implemented

- Check an application after deployment only when Apache reports its domain active. Use a bounded local request to `127.0.0.1` with the project domain in `Host`; disable redirects so the check does not depend on external DNS. A connection failure or HTTP 5xx fails deployment. Other HTTP responses count as a response because an app may intentionally redirect or return 404 at `/`.
- Keep the existing manual Deploy now retry, deployment history, status/timestamps, bounded and redacted output, per-project concurrency protection, and queue worker restart.

### Decisions

- Do not add automatic retries: the job runs database migrations, and an administrator can review the recorded failure before manually deploying again.
- Do not add rollback or atomic release directories. Code is updated in place, and restoring a commit cannot undo migrations that changed the database schema; release directories would add substantial complexity to this deployment flow.
- Do not add maintenance mode: the current deployment steps do not require it.

Pest covers successful, skipped, and failed post-deployment health checks while faking process and HTTP calls. No test contacts a public application or production service.

## RUN 11 — Production Installer — Complete

### Objective

Install Laravel Manager on a clean Ubuntu VPS with one command. Target Ubuntu 24.04 LTS.

### Scope

Support only Ubuntu 24.04 on amd64 or arm64. Use Ubuntu packages for PHP 8.3, PHP-FPM, Apache, MySQL, Git, and Certbot; use a signed NodeSource Node 24 LTS APT source for the Vite 8 build; install Composer after verifying its official installer checksum. Do not install Docker, Redis, Cloudflare tooling, or packages for other operating systems.

Install Laravel Manager under `/opt/laravel-manager`, create its MySQL schema/user, set production environment values, restrict source and `.env` permissions, and leave only `storage` and `bootstrap/cache` writable by `www-data`. Install the existing Apache/MySQL helpers and narrow sudoers rules. Serve the manager on port 8080, future projects through Apache port 80, and run the database queue worker as `www-data` under systemd. Prompt for the initial administrator credentials and seed the existing administrator seeder without persisting the plaintext password. Laravel Manager and all PHP/queue work must run unprivileged.

Require the public GitHub repository URL through `LARAVEL_MANAGER_REPOSITORY`. Detect the public IPv4 address with a bounded request and set `APP_URL` to `http://PUBLIC_IP:8080`; retain a validated `MANAGER_URL` override for a manager domain or other browser-reachable URL. When UFW is already active, add only a TCP 8080 allow rule without enabling UFW or changing existing rules. On Ubuntu images using the supported Oracle `/etc/iptables/rules.v4` format, insert one inbound TCP 8080 allow rule after the existing SSH rule, validate and apply the complete persistent rules file, and enable `netfilter-persistent`. Preserve all existing rules. Fail before installation for unsupported restrictive firewall formats. Print the selected access URL and tell the administrator to allow inbound TCP 8080 in the VPS subnet/NSG; ports 80 and 443 are for managed sites. Never execute the installer against the development machine.

### Acceptance criteria

- Installer refuses non-root execution, non-Ubuntu-24.04 systems, unsupported architectures, invalid repository URLs, and an existing Laravel Manager installation before modifying the host.
- Installer installs required server packages and configures Apache/PHP-FPM, MySQL, Laravel Manager, its database, permissions, helper configs, sudoers, and the queue worker service.
- The manager serves from `public` on port 8080; application sites can use port 80; PHP-FPM and queue workers run as `www-data`, never root.
- `bash -n`, the installer dry-run, the full Pest suite, and the frontend build pass locally without running the installer on macOS.
- A disposable Ubuntu 24.04 arm64 Multipass VM installed commit `9210f04`; browser login and the manager on port 8080 worked, all 14 database tables were present, Apache/PHP-FPM/MySQL/queue services were active, and both sudoers files passed `visudo`.
- README.md, AGENTS.md, and RUNS.md document the supported platform, install steps, firewall note, services, and recovery boundary.

### Public-IP access follow-up — 2026-09-29

- The Oracle VPS browser check returned HTTP 200 at `http://129.148.51.135:8080/login` after its local INPUT chain allowed TCP 8080. The response HTML still referenced assets at the private address `10.0.0.140`, showing that installer `APP_URL` detection also needed to use the public IPv4.
- The installer now detects public IPv4 with an 8-second `curl` timeout and uses it for `APP_URL`. An explicit `MANAGER_URL` still overrides detection.
- Context7 Laravel 13 deployment docs confirm `artisan optimize` caches Blade views. The installer writes `APP_URL` before this command, so April UI's compiled absolute asset route uses the public manager URL.
- If UFW is already active, the installer adds only its TCP 8080 allow rule. On the observed Oracle Ubuntu format, it inserts the rule after SSH in `/etc/iptables/rules.v4`, validates the full file with `iptables-restore --test`, applies it, and enables `netfilter-persistent`. It does not edit SSH or the cloud subnet/NSG rule. Other restrictive firewall formats fail before package installation.
- Browser access still requires the administrator to allow inbound TCP 8080 in the VPS subnet/NSG. The installer prints the detected public URL and this single network step. Port 8080 is plain HTTP; restrict the subnet rule to trusted source IPs where possible.
- Local verification: `bash -n scripts/install.sh` passed; `vendor/bin/pest tests/Feature/InstallerTest.php` passed (23 tests, 106 assertions); the local installer `--dry-run` exited 0. Pest exercises the firewall-rule insertion against an existing SSH rule, a duplicate Manager rule, a final reject, and another preserved rule.
- VM verification: `laravel-manager-run11` (Ubuntu 24.04.5 LTS, 192.168.252.6). Inspected `/opt/laravel-manager`, service state, and `/etc/iptables`; Apache, PHP 8.3-FPM, MySQL, and the queue were active, the app directory existed, and this VM has no `/etc/iptables/rules.v4`. Transferred `scripts/install.sh` to `/tmp/laravel-manager-install.sh` and ran `multipass exec laravel-manager-run11 -- env LARAVEL_MANAGER_REPOSITORY=https://github.com/PhillipNobel/laravel-manager.git bash /tmp/laravel-manager-install.sh --dry-run`; it exited 0 and printed the public-IP URL plan and subnet TCP 8080 requirement. The dry-run made no system changes. Did not rerun the full installer or exercise live firewall application because this VM already contains a working installation and the project instructions prohibit overwriting it without explicit authorization.

## RUN 12 — Per-Application PHP and Database Choices — Complete

### Objective

Let the administrator choose each Laravel application's PHP runtime and database engine when creating the app.

### Scope

Add PHP 8.2, 8.3, and 8.4 choices and MySQL/PostgreSQL selection to Create App. Store each choice on its Project and show it on the project detail page. The Settings PHP version remains only a form default. Route database provisioning through the selected engine's root-owned helper, using a separate database and least-privilege account for each app. Route HTTP/HTTPS Apache sites to the selected PHP-FPM socket and run Composer/Artisan deployment commands under the selected PHP CLI. Keep Laravel Manager itself on PHP 8.3 and its production database on MySQL.

Ubuntu 24.04's archive supplies PHP 8.3; the production installer adds the signed `ppa:ondrej/php` source for PHP 8.2 and 8.4 and installs all three PHP-FPM runtimes, database drivers, MySQL, and PostgreSQL. The settings page detects running FPM/database services and each PHP/database driver pair. Create App leaves unavailable options visible but disabled with the missing requirement; the Livewire save action checks availability again. The installer is the only flow that installs these packages. Do not install packages dynamically from a web request, accept arbitrary runtime names, or add remote database providers.

### Acceptance criteria

- Create App offers only the supported PHP versions 8.2, 8.3, and 8.4 and the MySQL/PostgreSQL database choices; values are validated against an explicit allowlist.
- An unavailable runtime or database/driver option is visible, disabled, and accompanied by its missing package or service requirement; backend save validation repeats the server checks.
- Each Project persists its own PHP version and database engine. The Settings default may preselect PHP but does not override the saved project value.
- Project detail shows both selections, Apache uses that PHP-FPM socket for HTTP/HTTPS, and deployment uses that PHP CLI without changing Laravel Manager's own runtime/database configuration.
- Each application receives a separate database and least-privilege account through a fixed root-owned helper. Credentials are written safely to the app `.env`, never stored on Project, shown in the UI, or written to logs.
- The installer installs PHP 8.2/8.3/8.4 with MySQL/PostgreSQL drivers and both database services; no OS package installation is triggered by an HTTP request.
- Pest covers form validation, persistence, engine-specific provisioning dispatch, unavailable prerequisites, failures, and secret handling. Tests fake all helper/process calls and connect to no real database.
- Chrome DevTools verifies Create App and Project detail for both engine choices, validation, responsive behavior, and browser console.
- README.md, AGENTS.md, and RUNS.md document the supported choices and security boundaries.

## RUN 13 — First-Run Setup — Complete

### Objective

Create polished onboarding for a newly installed server.

### Implemented flow

The installer creates the administrator before first sign-in. The authenticated wizard confirms that account, configures the base applications domain, public IP, applications directory, and default PHP version, runs read-only server checks, offers optional GitHub OAuth, and asks the administrator to confirm a wildcard DNS record. Completion opens Apps with its first-app empty state.

### Acceptance criteria

- The installer-created administrator signs in to a protected setup route; no public registration or second account-creation step is added.
- Freshly seeded installations are marked incomplete once. Re-running the seeder does not reset completed setup, and existing installations without a setup marker continue to work.
- Apps, Server, Settings, and repository management require setup completion. The authenticated setup route, OAuth connect/callback, and logout remain available during setup.
- Setup validates and saves the base domain, public IP, applications directory, and default PHP version. Domain, path, and PHP validation follow Settings; a public IP is required for the wildcard DNS target.
- Server checks use the existing fixed, read-only local checks. Missing requirements are shown with their prerequisite and do not trigger installation or block completion.
- GitHub can be connected through the existing OAuth flow but remains optional; setup progress survives the OAuth redirect.
- The DNS step shows the wildcard record name/type/target and requires an explicit administrator confirmation. It performs no Cloudflare API or external DNS lookup.
- Completing setup marks it complete and redirects to Apps, which shows a success message, empty state, and Create App action.
- Pest covers setup access, login redirect, validation/persistence, server-check gating, optional GitHub, manual DNS confirmation, seeder idempotence, and compatibility for existing installations. Process calls are faked.
- Chrome DevTools verifies wizard steps, actions, responsive layout, and the browser console at desktop and narrow/mobile sizes.
- README.md, AGENTS.md, and RUNS.md describe the onboarding flow and its safety boundary.

## RUN 14 — Hardening & Production Readiness — Complete

### Objective

Review the complete system for safe real-world VPS use.

### Review

Authentication, authorization, CSRF, webhook verification, secret storage, filesystem permissions, sudoers, shell injection, argument escaping, database credentials, deployment locking, Apache validation, error handling, auditability, logs, and sensitive output filtering. Review every OS command call. Never expose arbitrary command execution through HTTP.

### Implemented

- Audited authentication/session handling, authenticated routes, the exact GitHub webhook CSRF exception and HMAC verification, OAuth state/PKCE, encrypted token storage, deployment locks/output, fixed process argument arrays/timeouts, root-owned helper boundaries, sudoers patterns, project paths, environment-file handling, and the production installer.
- Hid GitHub access tokens from Eloquent serialization and locked the Project model property used by privileged Livewire actions.
- Limited GitHub webhook bodies to 25 MiB, verified push-specific payload fields, deduplicated signed body fingerprints, and added output redaction for URL, DSN, and credential environment variables.
- Documented the production trust model and warned that port 8080 uses HTTP.

### Verified limits

- The current installer shares `www-data` between Manager PHP-FPM, the queue worker, managed application PHP-FPM, and deployment scripts. Treat all connected repositories as trusted; this is not a multi-tenant isolation boundary.
- Manager port 8080 is plain HTTP. Keep it private, use a trusted source-IP restriction, SSH tunnel, or TLS reverse proxy before entering credentials. Manager TLS provisioning is not part of this RUN.

Pest covers Livewire model locking, token serialization, webhook size limits, and secret redaction. All process calls remain bounded and structured; all root operations remain behind the existing fixed helpers or the explicitly root-only installer.

## RUN 15 — Manager Updates — Complete

### Objective

Update Laravel Manager itself safely through a root-owned `laravel-manager update` command; show its installed version through `laravel-manager version`. Keep self-updates out of HTTP requests.

Support application code and Composer dependencies, frontend assets, migrations, cache rebuild, service restart, and version display. Use the simplest safe update mechanism; do not build a complex package distribution system.

### Acceptance criteria

- A root-owned `/usr/local/bin/laravel-manager` command supports `version` and `update`; updates require `sudo` and accept no user-selected command, path, remote, or branch.
- The updater follows the installed HTTPS GitHub origin and checked-out branch, repairs only installer-induced executable-mode drift when file contents still match Git, refuses other dirty/non-fast-forward checkouts, and does nothing to Manager services when no update is available.
- During an update, the app enters maintenance mode; Apache, all active supported PHP-FPM services, and the queue stop. Tracked Manager source stays root-owned; Composer may write the vendor directory as `www-data`, then ownership is restored before services restart. npm, migrations, and Artisan run as `www-data`.
- The update installs Composer dependencies, builds frontend assets, runs migrations, clears and rebuilds Laravel caches, refreshes the CLI command, restarts services, and verifies `http://127.0.0.1:8080/login`.
- Failure cleanup restores vendor permissions, attempts to leave maintenance mode, removes incomplete frontend artifacts, and restarts stopped services. A root-owned marker lets the same command resume after a failed or interrupted update; concurrent updates are locked. The updater never performs an automatic database/code rollback.
- Pest covers installer command installation and CLI argument restrictions; Bash syntax validation and the local Pest suite pass; frontend assets build successfully.
- The actual update command is smoke-tested on `laravel-manager-run11` Ubuntu 24.04, including before/after version output, service status, source ownership, and local login HTTP status. Record the exact result; do not reset or reinstall the VM.
- README.md, AGENTS.md, and RUNS.md document the command, update downtime, recovery behavior, security boundaries, and VM verification.

### Implemented and verified

- Added `scripts/laravel-manager`, installed by the production installer as root-owned `/usr/local/bin/laravel-manager`. `sudo laravel-manager version` shows the installed Git version; `sudo laravel-manager update` performs the update from the installed HTTPS GitHub remote and checked-out branch.
- The updater refuses dirty checkouts and non-fast-forward updates, accepts no user-provided process arguments, uses a lock against concurrent runs, and makes no service changes when already current.
- Updates run in maintenance mode. Apache, the Manager PHP 8.3-FPM service, any other active supported PHP-FPM services, and the queue stop for the update window. Composer runs as `www-data`; frontend dependencies/build run in a temporary source archive without the production `.env`; Artisan migrations and optimization also run as `www-data`. Root ownership and service state are restored before the local HTTP check.
- A root-only marker records incomplete updates and the previously active app PHP-FPM services, allowing the same command to resume safely. No database/code rollback is attempted.
- Fixed installer permission normalization so Git-tracked executable files retain their executable bit. This prevents fresh installs from appearing dirty to the updater.
- Added a safe repair for legacy installs: restore the executable bit only when the tracked file's content hash matches the Git index. Content changes remain blocked. README documents the one-time bootstrap command for installations that predate RUN 15.
- Pest: 172 tests, 810 assertions passed. Bash syntax, Pint, frontend production build, and `git diff --check` passed.

### Ubuntu VM verification

- VM: `laravel-manager-run11`, Ubuntu 24.04.5 LTS, arm64, `192.168.252.6`.
- The RUN 15 CLI was transferred from the local worktree into `/usr/local/bin/laravel-manager` on the VM for testing; unpublished workspace changes were not pushed.
- The test began at commit `9210f04`. `sudo laravel-manager update` fetched and fast-forwarded to `4bf5877`, installed Composer dependencies, installed 35 npm packages in the temporary build area, built Vite assets, cleared/rebuilt Laravel caches, found no pending migrations, restarted services, and returned successfully.
- GitHub `main` at test time advanced only README/RUNS; the lock file had no dependency changes and the database had no pending migrations. The smoke test exercised the update command and service lifecycle, but did not apply a dependency or schema change.
- `sudo laravel-manager version` reported `Laravel Manager 4bf5877`. A second update reported “already up to date” without restarting services.
- After update, MySQL, PHP 8.3-FPM, Apache, and the queue were active; PHP 8.2/8.4-FPM remained inactive as before. `/login` returned HTTP 200, the checkout was clean, source/vendor/build ownership was root-owned, and update markers/temp build directories were absent.
- Simulated the old installer permission drift with `chmod 0640` on tracked `scripts/install.sh`. The updater restored the executable mode only after verifying the indexed content hash, then reported “already up to date”; the checkout was clean, services stayed active, and `/login` remained HTTP 200.
- The first VM pass exposed that Composer started outside the application directory; the command now sets its working directory and a process-local Git safe-directory value. The successful verification above was repeated with that fix. The VM was not reinstalled or cleared.

## RUN 16 — Final Polish — Complete

### Objective

Review the full MVP as a cohesive product using Caveman, Impeccable, Context7, and Chrome DevTools MCP.

Review simplicity, architecture, naming, unused abstractions, UI consistency, mobile behavior, errors, loading/empty states, forms, project creation, deployments, Settings, GitHub connection, onboarding, and docs. Remove unused code, speculative architecture, unnecessary packages, duplicate components, and visual inconsistencies. Keep focus on: install Laravel Manager -> Create App -> Clone -> Develop -> Push -> Deploy.

### Review and changes

- Reviewed the Laravel/Livewire structure, OS process boundaries, settings, GitHub flow, onboarding, app creation, project actions, deployment states, and project documentation. Existing Actions and Support classes each serve current behavior; no packages or speculative abstractions were added or removed.
- Used April UI components and the committed moss/clay design system. Impeccable review found duplicate Create App links in the empty Apps state; the header action now appears only when the app list has rows, leaving one clear first-app action. The list retains its header action when populated.
- Updated README.md and RUNS.md to agree that RUN 01–16 are complete and corrected the recorded RUN 15 assertion count.

### Local verification

- Laravel Framework 13.33.0; Livewire 4.4 and April UI 1.3 remain the installed UI stack. No dependencies were added or removed.
- Pest: 172 tests, 811 assertions passed.
- `npm run build`, `vendor/bin/pint --test`, `composer validate --no-check-publish`, installer `--dry-run`, Bash syntax checks, and `git diff --check` passed.
- Context7 consulted for Laravel 13 Process API/testing and Livewire 4 locked-property/security guidance.
- Impeccable `context.mjs` and its one required detector run were used. The detector reported no findings for `resources/views/livewire/apps/index.blade.php`.

### Chrome DevTools and Ubuntu VM verification

- VM: `laravel-manager-run11`, Ubuntu 24.04.5 LTS, arm64, `192.168.252.6`. Existing install remained on `4bf5877`; MySQL, Apache, PHP 8.3-FPM, and `laravel-manager-queue` were active, and `/login` returned HTTP 200 before testing.
- Transferred the current workspace to an isolated `/tmp/laravel-manager-run16` copy and used a dedicated temporary MySQL schema for browser checks; did not change `/opt/laravel-manager` or its database.
- Chrome DevTools tested login/logout, each setup step, the Apps empty state, populated app list, Create App's GitHub-not-connected recovery state, Settings save, Server checks, project statuses/deployment output, mobile navigation, and desktop/mobile overflow. The VM server checks reported 7/10 available, matching installed services. Browser console had no messages.
- Desktop at 1440px and narrow layout at 500px had no horizontal overflow. The empty-state refinement was covered by a Pest regression test; the populated list keeps its top-right Create App action.
- GitHub OAuth and real deployment were not run against an external repository. Their API/process paths remain covered by local fakes; no external account or live app was used for this visual pass.

## RUN 17 — Create GitHub Repository from Create App — Complete

### Objective

Let the administrator create a private GitHub repository from Create App, with a usable Laravel starter committed to its `main` branch. Keep the existing-repository workflow available.

### Scope

- Add an explicit choice between an existing connected repository and a new private repository.
- Derive the new repository name from the validated subdomain and show the connected GitHub owner/name before submission.
- Generate a trusted `laravel/laravel` starter using Composer. Select Laravel 12 for PHP 8.2 and Laravel 13 for PHP 8.3/8.4.
- Run `composer create-project` with `--no-install`, `--no-scripts`, and `--no-plugins`; do not install dependencies or execute starter scripts.
- Create the authenticated user's private repository through GitHub REST `POST /user/repos` with `auto_init=false`, make a fixed initial Git commit, and push `main` using the OAuth token only through transient Git environment configuration.
- Continue through the existing Project provisioning flow so the Manager clones the new repository into the applications root and writes the local production `.env`.
- Keep OAuth credentials out of arguments, clone URLs, `.git/config`, Project fields, browser output, and process logs. Bound all Composer and Git calls and clean the Manager-owned staging directory in all outcomes.
- Explain when GitHub created the private repository but the initial push or later clone failed; do not automatically delete a remote repository.

### Explicitly excluded

Do not create public repositories, GitHub webhooks, repository deployment keys, databases, Apache sites, SSL certificates, or automatic deployments. Do not run Composer dependency installation, starter scripts, or repository-provided code during Create App. Do not add arbitrary repository names, branches, commands, or URLs to process arguments.

### Acceptance criteria

- Create App lets the administrator choose an existing repository or create a new private repository. The new option works even when the connected account has no repositories.
- New repository name is derived from the validated application subdomain, the repository is private, `main` is pushed with a Laravel starter compatible with the selected PHP version, and the Project records its canonical GitHub URL/name.
- The existing repository selection, verification, branch choice, and provisioning behavior continue to work.
- Laravel starter files are generated in a unique Manager-owned staging directory. Composer does not install dependencies, load plugins, or run scripts; the staging directory is removed after success or failure.
- GitHub REST and process failures produce safe, actionable feedback. A remote repository created before a push failure remains linked to the failed Project; credentials and raw process output are not logged.
- Pest covers the Livewire workflow, private repository request/response validation, Composer/Git commands, successful push and clone, existing-repository regression, and failures. Tests fake every GitHub request and Process call.
- Chrome DevTools tests the existing/new choices, empty repository list, validation, domain preview, success and failure states, navigation, desktop and mobile layout, and browser console.
- Context7 documents GitHub REST, Composer, and Laravel version compatibility; April UI and Impeccable review the Create App form; Caveman reviews the architecture.
- The full local Pest suite and frontend build pass. The new-repository path is tested on the existing Ubuntu 24.04 Multipass VM with an isolated test copy; the existing Manager install and database are preserved. Record commands and outcomes.
- README.md, AGENTS.md, and RUNS.md document the new workflow and security boundary.

### Verification results

- Local: the existing-repository path remains available. New-repository tests fake every GitHub request and Composer/Git/clone process, including successful provisioning, an invalid GitHub response, GitHub creation failure, push failure, Laravel version selection, credential redaction, and staging cleanup. The invalid-subdomain test confirms no repository name is previewed until the subdomain is valid.
- VM: `laravel-manager-run11`, Ubuntu 24.04.5 LTS, arm64, Multipass address `192.168.252.6`. Inspected the VM before testing; its installed Manager at `/opt/laravel-manager`, Manager database, Apache, MySQL, PHP-FPM, and queue were left intact. Transferred the current source into `/tmp/laravel-manager-run17-test`, used a dedicated temporary MySQL schema/user because this image has no `pdo_sqlite`, and served the isolated copy with `php artisan serve --host=0.0.0.0 --port=8081`. Removed no installed application data.
- VM command smoke: as `www-data`, `composer create-project laravel/laravel:^13.0 <unique-temp-path> --no-install --no-scripts --no-plugins --no-interaction --prefer-dist` generated a Laravel starter without `vendor`, `.env`, or `.git`. Then `git init --initial-branch=main`, `git add --all`, a fixed initial commit, and `git push --set-upstream origin main` succeeded against a temporary local bare repository. No live GitHub repository was created and no real OAuth token was used.
- A first manual Composer probe was launched with current directory `/home/ubuntu`, which `www-data` cannot enter; Symfony Process correctly failed at `chdir`. Repeating the smoke from accessible `/tmp` succeeded. The production action explicitly sets `Process::path()` to its Manager-owned staging directory, so no code change was needed for this test-only working-directory mistake. Composer's warning about its default cache under `/var/www` was nonfatal.
- Chrome DevTools against the VM copy checked both source choices: an empty repository list and its recovery state, then an existing repository with its default branch, plus switching to new private repository, connected-owner/name preview, valid and invalid subdomain feedback, validation errors, and simulated successful create/provision flows for both source types. The browser-only success scenarios used a temporary `AppServiceProvider` in the isolated copy with `Http::fake` and `Process::fake`; no real GitHub repository was created. At 1440px desktop and 390px mobile, `document.documentElement.scrollWidth` equaled `innerWidth`; no horizontal overflow. The mobile menu opened and the Create App route remained usable. Browser console had no messages. Browser network traffic was limited to the isolated VM application and its Livewire updates.
- UI review found that an invalid subdomain could appear as a repository-name preview. The preview now waits for a valid subdomain, with a Pest regression test. April UI's existing input, select, button, helper-text, and alert styles remain in use; no new UI dependency or component layer was added.
- Context7 was consulted for GitHub REST repository creation and OAuth scope, Composer `create-project` options, and Laravel Process configuration/testing. `composer create-project --help` confirmed the local Composer options. The production repo uses the existing OAuth `repo` scope and private repository creation.
- Final local verification: Pest passed 180 tests and 865 assertions; `vendor/bin/pint --test`, `composer validate --no-check-publish`, `npm run build`, and `git diff --check` all passed.

### VM commands and isolation

```text
multipass info laravel-manager-run11
multipass exec laravel-manager-run11 -- lsb_release -a
multipass transfer /private/tmp/laravel-manager-run17-source.tar.gz laravel-manager-run11:/tmp/laravel-manager-run17-source.tar.gz
# Extracted to /tmp/laravel-manager-run17-test; installed Manager checkout/database were not used.
cd /tmp/laravel-manager-run17-test && php artisan serve --host=0.0.0.0 --port=8081
# Chrome DevTools opened http://192.168.252.6:8081/apps/create at 1440px and 390px.
```

The isolated UI copy used a test-only MySQL schema `laravel_manager_run17_ui`, a separate local test user, and a fake GitHub connection. Its database and temporary source copy are test artifacts; the pre-existing `/opt/laravel-manager` application and database were not changed. A temporary provider in the copy faked GitHub REST and Composer/Git processes for the browser success path. The temporary HTTP server was stopped after Chrome checks.

## RUN 18 — VPS setup guide and theme selector — Complete

### Objective

Document practical GitHub, DNS, firewall, and TLS setup for new VPS installations, and add a user-selectable dark theme to the authenticated Manager interface.

### Scope

- Add a step-by-step README guide for Cloudflare DNS, OCI provider rules and Ubuntu host firewalls, initial public-IP access, GitHub OAuth setup, TLS reverse proxy for the Manager panel, and Let's Encrypt HTTPS for managed applications.
- Include the verified diagnostics and recovery sequence for the OCI case where Apache returned HTTP 200 locally but the host iptables INPUT reject blocked public port 8080.
- Add System, Light, and Dark theme choices to the authenticated header, follow system preference by default, and persist an explicit choice in browser local storage.
- Trust HTTPS-forwarding headers only from the loopback Apache reverse proxy used by the documented Manager TLS setup.
- Keep April UI components, existing design tokens, and dependencies; do not change installer, firewall, OAuth, or certificate behavior.

### Acceptance criteria

- README describes provider and host firewall layers independently, documents the host port 8080 diagnostic, and links to current official Cloudflare, OCI, GitHub, Apache, and Let's Encrypt documentation.
- README explains the GitHub OAuth callback, required environment values, encrypted credential behavior, the `repo` scope, and the verified Manager/app HTTPS setup.
- The authenticated header offers accessible System, Light, and Dark menu items using April UI; the initial theme avoids a light flash, follows system settings by default, and saves explicit choices locally.
- Laravel recognizes forwarded HTTPS from the documented loopback reverse proxy and ignores the same forwarded header from a remote client.
- Dark colors maintain readable foreground, input, border, sidebar, and menu contrast without adding dependencies.
- Pest covers the authenticated theme selector markup and preference bootstrap. Browser verification tests selecting all three modes and persistence after reload.
- Full Pest suite, frontend production build, formatting, and Composer validation pass.
- Impeccable reviews desktop and narrow layouts; Chrome DevTools checks interactions, theme persistence, overflow, and console output against the isolated Ubuntu VM copy.
- The existing Manager install and database on the VM remain untouched; this RUN records VM identity, commands, and outcomes.
- README.md, AGENTS.md, and RUNS.md document the user setup guide, theme preference, and loopback proxy boundary.

### Verification results

- README now includes DNS-only Cloudflare setup, A/AAAA guidance, separate OCI provider and Ubuntu host firewall rules, the observed INPUT reject and its persistent-rule recovery, public-IP `APP_URL` recovery, GitHub OAuth creation/callback/scope setup, Apache TLS reverse proxy for the Manager, secure cookies, Cloudflare Full (strict), and app certificate renewal checks. It links to official Oracle, Cloudflare, GitHub, Apache, Certbot, and Let's Encrypt documentation.
- The header's April UI dropdown offers accessible System, Light, and Dark menu radio choices. The inline bootstrap applies the saved/system choice before styles load; CSS defines dark surfaces and text through existing tokens and sets the native browser color scheme. Explicit choices are browser-local. No package or component abstraction was added.
- Laravel trusts forwarded proxy headers only from `127.0.0.1`; Pest verifies that local Apache's `X-Forwarded-Proto: https` is recognized and the same header from a remote client is ignored.
- Context7 was consulted for Tailwind CSS 4 manual dark-mode selectors and Laravel 13 trusted-proxy configuration. April UI's dropdown menu/item pattern was used. Impeccable review of the desktop and narrow screenshots found the theme hierarchy, token contrast, spacing, and controls consistent with the restrained admin shell; no extra decoration or new component layer was needed. Caveman review kept the change in the existing layout/CSS and avoided a package, settings table, service, or theme abstraction.
- Local verification: Pest passed 183 tests and 875 assertions; `vendor/bin/pint --test`, `composer validate --no-check-publish`, `npm run build`, and `git diff --check` passed.
- VM verification: inspected `laravel-manager-run11` before changes. It is Ubuntu 24.04.5 LTS, arm64, Multipass IP `192.168.252.6`; the installed `/opt/laravel-manager` at `4bf5877`, Apache, MySQL, PHP 8.3-FPM, queue, and Manager database remained intact. The test used source in `/tmp/laravel-manager-run18-theme`, a dedicated schema `laravel_manager_run18_ui`, and port 8082. The temporary browser server was stopped and port 8082 verified closed.
- Chrome DevTools signed in to the isolated app and selected System, Light, and Dark through the actual header menu. It confirmed System followed emulated OS dark and light changes, explicit Light survived reload, and explicit Dark stayed active on later navigation/reload. Apps and Settings were inspected at 1440×900; Apps, Create App, and Settings were checked at 390×844 in dark mode. At 390px, document and body widths both matched the viewport; browser console had no messages. The Manager login endpoint returned HTTP 200. No live GitHub OAuth, external repository, firewall rule, or Let's Encrypt request was used.

### VM commands and isolation

```text
multipass info laravel-manager-run11
multipass exec laravel-manager-run11 -- bash -lc '. /etc/os-release; echo "$PRETTY_NAME"; sudo git -C /opt/laravel-manager rev-parse --short HEAD; sudo systemctl is-active apache2 php8.3-fpm mysql laravel-manager-queue'
multipass transfer /private/tmp/laravel-manager-run18-theme.tar.gz laravel-manager-run11:/tmp/laravel-manager-run18-theme.tar.gz
# Extracted to /tmp/laravel-manager-run18-theme; copied the existing vendor tree into this isolated source copy.
# Created only the dedicated laravel_manager_run18_ui test schema/user and a temporary administrator.
sudo -u www-data /usr/bin/php8.3 /tmp/laravel-manager-run18-theme/artisan key:generate --force
sudo -u www-data /usr/bin/php8.3 /tmp/laravel-manager-run18-theme/artisan migrate --seed --force
sudo -u www-data /usr/bin/php8.3 /tmp/laravel-manager-run18-theme/artisan optimize
multipass exec laravel-manager-run11 -- sudo -u www-data bash -lc 'cd /tmp/laravel-manager-run18-theme && nohup /usr/bin/php8.3 artisan serve --host=0.0.0.0 --port=8082 >/tmp/laravel-manager-run18-theme.log 2>&1 </dev/null &'
# Chrome DevTools tested http://192.168.252.6:8082 at 1440x900 and 390x844.
# Transferred the final bootstrap/app.php to the isolated copy and reran artisan optimize as www-data.
# Stopped the exact temporary server PID and verified no listener remained on port 8082.
```

## RUN 19 — Manager Updates in the Panel — Complete

### Objective

Allow the administrator to check for and install Laravel Manager improvements from Settings, reusing the RUN 15 updater. Keep the existing CLI recovery path available.

### Desired flow

```text
Settings → Manager updates → Check for updates
    → Review available update → Update now → Confirm interruption
    → Update runs independently → Panel reconnects → Result
```

### Scope

- Add a Manager updates section to Settings without adding another primary navigation item.
- Display the installed version/tag when available, short commit, available commit, last check, and last update result. Do not require tagged releases: the current installation follows its installed GitHub origin and branch.
- Provide explicit Check for updates and Update now actions. Show checking, up to date, update available, unavailable, queued, running, successful, failed, and interrupted states where appropriate.
- Confirm before updating. Explain that the Manager and managed applications can be temporarily unavailable because the existing updater stops shared Apache and active PHP-FPM services.
- Reuse `sudo laravel-manager update` as the update engine: fast-forward source, Composer dependencies, frontend build, migrations, caches, permission restoration, service restart, and local login verification.
- Execute updates through a dedicated root-owned systemd service that survives the HTTP request and the Manager queue shutdown. Do not execute the update in a controller, Livewire request, or Manager queue job.
- Record bounded, sanitized progress and outcome outside the Manager checkout and database, using atomic root-owned status files readable by the Manager. Persist source/target commits, phase, timestamps, and a safe error summary; never expose raw secrets or unrestricted system logs.
- Let the browser reconnect after the interruption and display the recorded result. Support safe manual retry/resume through the existing interrupted-update mechanism; retain the CLI as a recovery path when the panel cannot boot.
- Integrate the launcher, service, and exact permissions into new installations and the root CLI update path. Existing servers need one CLI update to receive this capability; do not require reinstalling or recreating their database.
- Disable the update action in local development or when the installed launcher is missing, with a clear requirement and documented CLI bootstrap instruction.

### Execution and security design

- RUN 15 currently forbids web-triggered updates. This RUN introduces only a fixed launcher for checking and starting the dedicated update service; AGENTS.md explicitly documents to describe that exception.
- Keep the updater, launcher, systemd unit, Git metadata, and update state root-owned. The web user must never receive permission to invoke the general updater, a shell, arbitrary `systemctl`, or file-writing commands through sudo.
- Accept no repository, branch, path, commit, command, or service name from the browser. Derive the source from the validated installed Git origin and branch. The root updater rechecks the remote before execution; the displayed available commit is informational and may advance before installation.
- Require administrator authentication and CSRF protection for starting an update. A confirmation dialog must describe the interruption and current lack of automatic database rollback.
- Serialize CLI and panel updates with the same operating-system lock. A repeated click or request must not start another updater. Bound update checks and avoid launching repeated checks during polling.
- Coordinate with provisioning/deployment operations: reject or defer an update while an operation is running and prevent new operations from starting once an update is accepted. The CLI waits at most 30 seconds for the shared operations lock and rechecks pending/running records before maintenance.
- Preserve `.env`, APP_KEY, administrator accounts, settings, projects, deployment history, and application directories. Never rerun initial setup or overwrite existing data. Preserve root ownership before restarting web services.
- Keep the current trusted-repository model explicit: Manager and managed applications share `www-data`. A narrow launcher is not per-project isolation. Root must validate its own inputs and installation state independently of Laravel.
- Keep progress free of OAuth tokens, database passwords, environment values, and sensitive Composer/Git diagnostics. Expose only allowlisted phases and bounded sanitized messages.
- No automatic database migration rollback is promised. If the update fails or the UI cannot reconnect, show the recovery command and retain durable status for diagnosis.

### Acceptance criteria

- Settings shows accurate installed and available commits, handles unavailable GitHub/network access, and does not offer an update when already current.
- Only an authenticated administrator can request an update; forged requests, unsupported inputs, duplicate requests, and missing launcher prerequisites are rejected.
- Starting an update returns promptly; the dedicated service continues when Apache, PHP-FPM, and the Manager queue stop.
- CLI and UI share concurrency protection. Busy provisioning/deployment blocks an update, and an accepted update blocks new infrastructure work.
- The panel reconnects after services return and shows success only after the local login health check passes. Failed and interrupted updates show a useful safe explanation and documented recovery steps.
- A dirty checkout or non-fast-forward remote blocks the update without damaging the installation. The existing interrupted-update recovery remains usable.
- New installs include the required OS integration. An existing install can gain panel updates through the documented CLI update without a reinstall.
- Existing Manager data, secrets, setup completion, managed application files, ownership, and service configuration survive an update.
- Pest covers authentication, update states, check failures, launch validation, duplicate requests, busy operations, and safe status rendering. Fake all HTTP/process calls in Laravel tests.
- Helper/service tests cover argument rejection, root ownership, state-file safety, locking, interrupted execution, and sanitized output without updating the development host.
- Run relevant tests, the complete Pest suite, formatting checks, and the frontend build successfully.
- Use Context7 for current Laravel/Livewire/process behavior and official systemd documentation for service execution. Apply Caveman to reuse the updater and avoid a second update engine.
- Use April UI and Impeccable for Settings composition, confirmation, status feedback, accessibility, and both light/dark themes. Verify desktop/mobile interactions, reconnect behavior, and console through Chrome DevTools MCP.
- Inspect and test on the existing Ubuntu 24.04 Multipass VM `laravel-manager-run11`; preserve its data. Verify the real launch, service interruption/restart, status persistence, concurrent-request rejection, and an update or interrupted-update recovery. Use controlled test fixtures for failure scenarios. Record commands, Ubuntu version, and outcomes here before marking complete.
- Update README.md with panel usage, existing-install bootstrap, interruption expectations, and CLI recovery; update AGENTS.md with the narrow launcher boundary.

### Explicit exclusions

No automatic scheduled updates, arbitrary update sources, branch/channel selection, generic terminal, package installation through HTTP, operating-system upgrades, application deployments, release-directory architecture, zero-downtime promises, or automatic database rollback.

### Implementation and verification

- Reused the Bash CLI updater and added one fixed Python bridge, a root-owned systemd oneshot service, exact sudo rules, and a tmpfiles definition for the operations lock. No dependencies, update tables, generic execution endpoints, or second update engine were added.
- Settings embeds a small Livewire update component. It shows safe version/commit metadata, checks, confirmation, phases, bounded step history, recovery commands, and browser reconnection independent of Livewire snapshot changes during an upgrade. April UI buttons and alert-dialog preserve the existing light/dark design. Impeccable review verified hierarchy, responsive composition, safe Cancel focus, readable warnings, and no overflow; corrected April header slot usage and a disabled-state binding.
- InfrastructureLock holds a shared file lock throughout current provisioning/repository/domain/database/SSL/deployment operations, including webhook dispatch and queued deployment execution. Nested calls reuse the outer lock. The root updater acquires the exclusive gate and uses the read-only `manager:update-ready` Artisan command as `www-data` to reject pending/running work before stopping services.
- Status/history is atomic and root-owned outside the application database. HTTP responses discard raw messages, diagnostics, malformed metadata, and arbitrary phases. Service stdout/stderr is suppressed; CLI diagnostics remain available to the root administrator. The common update lock lives in the protected installation state directory rather than a world-writable lock parent. Added bounded Git fetch, signal cleanup, and recovery of vendor ownership for an incomplete update.
- Existing-server compatibility: the legacy updater copies the new CLI but cannot install integration it did not know about. README therefore documents one initial `sudo laravel-manager update` followed by `sudo laravel-manager enable-panel-updates`; future CLI updates refresh the bridge automatically. New installers include it immediately.
- Local automated verification: full Pest suite passed (200 tests, 939 assertions); all 11 Python bridge contract tests passed. Pint, Composer validation, shell syntax checks, frontend build, and `git diff --check` passed. Laravel tests fake all process calls; helper contract tests mock network/systemd/updater calls and never update the development host.
- Documentation: Context7 Laravel 13 process timeouts/argument arrays/fakes and Livewire 4 locked properties/polling. Context7 has no April UI entry; consulted the installed April UI 1.3 Blade component sources. Public systemd documentation fetches failed, so consulted the Ubuntu VM's installed `systemd.service(5)` manual and validated the real unit with `systemd-analyze verify`.
- VM: `laravel-manager-run11`, Ubuntu 24.04.5 LTS, arm64, `192.168.252.6`. Inspected installation ownership, clean checkout, services, and empty project/job counts first. Preserved the installed Manager database, `.env`, administrator, app directories, and configuration; no reset/reinstall. Transferred unpublished source in `/tmp/laravel-manager-run19.tar.gz`.
- Real updater smoke test: installed the current root CLI/bridge from transferred files; `sudo laravel-manager update` fast-forwarded the existing installation from `4bf5877` to the already-published `35ba0da`, ran Composer/build/migrations/cache rebuild as `www-data`, restarted services, and verified HTTP 200 on `/login`. No unpublished source was pushed for this test.
- Isolated UI: dedicated `laravel_manager_run19_ui` MySQL schema, separate administrator, copied vendor with regenerated autoload, and source at `/var/www/laravel-manager-run19-ui`, served by temporary Apache/PHP-FPM virtual host on 8083. Initial temporary artisan-server/Apache port conflict and PrivateTmp access issues were diagnosed and corrected; installed services recovered before verification continued.
- Real service/browser integration: used a reversible root-owned updater fixture to hold the update/operations locks, stop Apache/PHP-FPM/queue, wait, restore services, and verify login. Chrome DevTools clicked Update now/Confirm update. The service remained activating while both 8080 and 8083 returned connection failures; a duplicate `start` was rejected. Services returned active, login returned HTTP 200, and the browser automatically reloaded Settings with Successful. The fixture and temporary preflight command were removed/restored afterward.
- Additional VM checks: exact sudo permits `status|check|start` but rejects `run`; a shared operations lock rejected `start` with exit 1; PHP InfrastructureLock returned BLOCKED during queued status and ALLOWED afterward. Installed bridge integration with its fixed root installer and validated sudoers/unit configuration. Simulated an incomplete marker for the installed commit and ran the real CLI: it resumed Composer/build/migrations, removed the marker, restored services, and verified login.
- Chrome DevTools: desktop 1440×1000 and true mobile emulation 390×844, light/dark presentation, confirmation/cancel focus, loading, success/failure recovery, update check, step history, automatic reconnection, and horizontal overflow checks. Connection errors during the deliberate Apache outage were expected; after reconnection the console had no unexpected warnings/errors.
- Cleanup: restored the real updater, removed only created OS/source fixtures, disabled the temporary UI vhost, and verified the original installation and services after testing. The isolated test directories/schema remain available for reproduction and do not replace production data.


## RUN 20+ — Pending

Do not begin another RUN until explicitly requested.
