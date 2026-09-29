# Laravel Manager RUN Roadmap

RUNs are strictly sequential. Implement only the RUN explicitly requested. RUN 01 through RUN 10 are complete. RUN 11 is current; RUN 12 and later remain pending until requested.

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
- A unique `X-GitHub-Delivery` identifier prevents repeated deliveries from queuing duplicate deployments, including concurrent requests.
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

## RUN 11 — Production Installer — Current

### Objective

Install Laravel Manager on a clean Ubuntu VPS with one command. Target Ubuntu 24.04 LTS.

### Scope

Support only Ubuntu 24.04 on amd64 or arm64. Use Ubuntu packages for PHP 8.3, PHP-FPM, Apache, MySQL, Git, and Certbot; use a signed NodeSource Node 24 LTS APT source for the Vite 8 build; install Composer after verifying its official installer checksum. Do not install Docker, Redis, Cloudflare tooling, or packages for other operating systems.

Install Laravel Manager under `/opt/laravel-manager`, create its MySQL schema/user, set production environment values, restrict source and `.env` permissions, and leave only `storage` and `bootstrap/cache` writable by `www-data`. Install the existing Apache/MySQL helpers and narrow sudoers rules. Serve the manager on port 8080, future projects through Apache port 80, and run the database queue worker as `www-data` under systemd. Prompt for the initial administrator credentials and seed the existing administrator seeder without persisting the plaintext password. Laravel Manager and all PHP/queue work must run unprivileged.

Require a public HTTPS GitHub repository URL through `LARAVEL_MANAGER_REPOSITORY` until the canonical repository URL is available to embed in the published installer. Print the server access URL and remind the administrator to allow ports 80, 443, and 8080 in the VPS firewall. Never execute the installer against the development machine.

### Acceptance criteria

- Installer refuses non-root execution, non-Ubuntu-24.04 systems, unsupported architectures, invalid repository URLs, and an existing Laravel Manager installation before modifying the host.
- Installer installs required server packages and configures Apache/PHP-FPM, MySQL, Laravel Manager, its database, permissions, helper configs, sudoers, and the queue worker service.
- The manager serves from `public` on port 8080; application sites can use port 80; PHP-FPM and queue workers run as `www-data`, never root.
- `bash -n`, the installer dry-run, the full Pest suite, and the frontend build pass locally without running the installer on macOS.
- Before marking RUN 11 complete, install on a disposable Ubuntu 24.04 amd64 or arm64 VPS and verify the manager login, app vhost port, database, queue service, and narrow sudo rules.
- README.md, AGENTS.md, and RUNS.md document the supported platform, install steps, firewall note, services, and recovery boundary.

## RUN 12 — First-Run Setup — Pending

### Objective

Create polished onboarding for a newly installed server.

### Example flow

Create administrator; configure applications domain; verify server; connect GitHub; verify wildcard DNS; finish. After setup, show Apps with a useful empty state and Create App action. Use April UI, Impeccable, and Chrome DevTools MCP extensively.

## RUN 13 — Hardening & Production Readiness — Pending

### Objective

Review the complete system for safe real-world VPS use.

### Review

Authentication, authorization, CSRF, webhook verification, secret storage, filesystem permissions, sudoers, shell injection, argument escaping, database credentials, deployment locking, Apache validation, error handling, auditability, logs, and sensitive output filtering. Review every OS command call. Never expose arbitrary command execution through HTTP.

## RUN 14 — Manager Updates — Pending

### Objective

Update Laravel Manager itself safely, through the UI or a simple `laravel-manager update` command.

Support application code and Composer dependencies, frontend assets, migrations, cache rebuild, queue restart, and version display. Use the simplest safe update mechanism; do not build a complex package distribution system.

## RUN 15 — Final Polish — Pending

### Objective

Review the full MVP as a cohesive product using Caveman, Impeccable, Context7, and Chrome DevTools MCP.

Review simplicity, architecture, naming, unused abstractions, UI consistency, mobile behavior, errors, loading/empty states, forms, project creation, deployments, Settings, GitHub connection, onboarding, and docs. Remove unused code, speculative architecture, unnecessary packages, duplicate components, and visual inconsistencies. Keep focus on: install Laravel Manager -> Create App -> Clone -> Develop -> Push -> Deploy.
