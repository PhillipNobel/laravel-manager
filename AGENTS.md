# Laravel Manager Agent Guide

## Purpose

Laravel Manager is a small self-hosted application for managing Laravel projects on the same Ubuntu VPS. Its product rule is one VPS, one Laravel Manager installation, many Laravel applications.

## Product philosophy

- Keep the product focused on Laravel applications on one Ubuntu VPS.
- Prefer simple Laravel conventions and obvious data flow.
- Do not add speculative features, abstractions, or dependencies.
- Do not build a generic server panel, fleet manager, cloud platform, or container orchestrator.
- Do not introduce Docker, React, Vue, Inertia, or Redis unless a future RUN explicitly requires it.
- Keep all source code, comments, and interface text in English.

## Architecture

- Use Laravel, Livewire, Alpine.js, Tailwind CSS, Blade, SQLite for local development, and Pest.
- Use Laravel conventions. Keep controllers and Livewire components thin.
- Add an Action only for a meaningful business operation; avoid unnecessary service and repository layers.
- Use enums when they make persisted states clearer.
- Use database transactions when several writes must remain consistent.
- Never store application database passwords on the Project model.
- Add infrastructure behavior only in the RUN that owns it.

## UI

- Use April UI as the primary component and template reference.
- Use the Impeccable skill for interface review and improvement. Keep its recommendations within this product's restrained operating UI.
- Verify meaningful UI work in Chrome DevTools MCP at desktop and narrow/mobile sizes, including interactions and browser console.

## Mandatory Ubuntu VM verification

- Test every RUN on the existing disposable Ubuntu 24.04 Multipass VM `laravel-manager-run11` (or the current Ubuntu VM explicitly selected for this project), in addition to local automated checks.
- Inspect the VM and installed services before changing it. Reuse its current state; never delete, reset, reinstall, or overwrite its application data without the user's explicit authorization for that action.
- Test the RUN's real server behavior in the VM. When changes are not published, transfer the current project files to the VM for testing rather than pushing unfinished work to GitHub.
- For UI changes, use Chrome DevTools against the VM-hosted app when the changed code can be loaded there; check desktop and narrow/mobile layouts and the browser console.
- Record the VM name, Ubuntu version, commands, and outcomes in the current RUN documentation. Do not mark a RUN complete based only on local tests. If a required VM check cannot run, record the exact blocker and leave the RUN incomplete.

## Documentation and current RUN

- `RUNS.md` is the source of truth for the sequential project roadmap.
- Implement only the RUN explicitly requested by the user. Do not start a later RUN automatically.
- Every RUN needs a defined scope, acceptance criteria, automated tests, relevant test execution, and documentation updates where appropriate.
- Stop after the current RUN and report its outcome.

## Documentation and tools

- Consult Context7 MCP for current framework, library, package, API, and tooling behavior before relying on version-sensitive implementation details.
- Use Chrome DevTools MCP for required browser verification after meaningful UI work.
- Use April UI references before creating custom UI patterns.
- Apply Caveman principles: fewer dependencies, fewer abstractions, direct names, clear code, and Laravel conventions.
- Apply Impeccable for UI/UX review without adding technical complexity.

## Local development

- Use SQLite locally. Keep `.env` and generated secrets out of Git.
- Keep `.env.example` useful and safe to commit.
- Pest is the automated test framework.
- Build frontend assets with the documented Vite command.

## RUN 01 safety boundary

RUN 01 project creation writes database records only. Do not execute operating-system commands or provision directories, databases, GitHub resources, Apache sites, SSL, sudo access, or Linux users in RUN 01.

## RUN 02 safety boundary

RUN 02 may read local environment details and execute only fixed, read-only checks for installed software versions. Pass fixed argument arrays to Laravel's Process API, cap each check at two seconds, and never interpolate user input into a process command. Do not make external network requests, install packages, change Apache, create databases, or provision projects in RUN 02.

## RUN 03 safety boundary

RUN 03 may connect one administrator account through a GitHub OAuth App, validate the account, list repositories, and revoke the connection. Protect the OAuth callback with one-time `state` and PKCE, encrypt stored access tokens, and never render or log credentials. Do not create repositories, configure deployment access, add webhooks, or deploy projects in RUN 03.

## RUN 04 safety boundary

RUN 04 may clone a repository selected from the connected GitHub account into the configured applications root. Use fixed argument arrays with Laravel's Process API, validate branches with Git, enforce slug-derived paths, refuse existing destinations, and use bounded timeouts. Pass the OAuth token only through transient process environment configuration; never put it in command arguments, clone URLs, `.git/config`, database fields, UI output, or logs. Generate `.env` with a fresh key and restrictive permissions. Do not run repository code or Composer scripts, install dependencies, create GitHub repositories, provision databases, modify Apache, or configure SSL.

## RUN 05 safety boundary

RUN 05 manages HTTP Apache sites only through the standalone root-owned `laravel-manager-apache` helper. Laravel must run unprivileged and may call only `enable|status <validated-slug>` through non-interactive sudo. The helper reads base domain and applications root from root-owned `/etc/laravel-manager/apache.json`, derives domain/path itself, rejects symlinks, traversal, unsafe values, and unmanaged site files, uses fixed command arguments, and validates Apache configuration before graceful reload. Sudoers must authorize the helper only, never a shell, Apache commands, file writers, or arbitrary execution. Fake every process in Laravel tests. Do not add Cloudflare DNS, MySQL, SSL, arbitrary shell access, or behavior from another RUN.

## RUN 06 safety boundary

RUN 06 may provision one MySQL schema and one dedicated account per active Project only through the standalone root-owned `laravel-manager-database` helper. Laravel remains unprivileged and may invoke only that helper with `provision` and a validated project slug through non-interactive sudo. The helper reads `/etc/laravel-manager/database.json`, uses local socket-authenticated MySQL root, confirms Git ignores the app `.env`, rejects existing generated names, constructs identifiers only from the slug, grants privileges only on the matching database, and never gives the application account `GRANT OPTION`. It writes `.env` through a pinned project-directory file descriptor, atomically, preserving owner and mode `0600`; it clears `DB_URL`, sets `DB_SOCKET`, and verifies the application account before success. Never pass the password through arguments or environment, store it on Project/in Laravel Manager's database, write it to logs, or show it in the UI. The helper must suppress raw MySQL diagnostics and emit only safe error codes. Fake every helper process in tests; never connect tests to local or production MySQL.

## RUN 07 safety boundary

RUN 07 may queue manual deployments only for an active Project with an active database, configured GitHub repository, safe project path, and connected GitHub account. Use the database queue and fixed Laravel Process argument arrays with bounded timeouts; never expose arbitrary command execution or run as root. Pass the GitHub token only through temporary Git environment configuration. Verify origin, branch, commit, `.env` ignore status, and that the fetched commit does not track `.env`; recheck required files after checkout. Redact GitHub credentials and `.env` secrets from deployment output, bound stored logs, record commit/status/timestamps, and prevent concurrent pending/running deployments per project. Fake every Process call in tests; never run a real deployment during tests. Do not add webhooks, automatic deploys, rollback, release directories, or RUN 08 behavior.

## RUN 08 safety boundary

RUN 08 accepts GitHub webhooks only through the dedicated unauthenticated endpoint after validating `X-Hub-Signature-256` against the exact raw request body with `GITHUB_WEBHOOK_SECRET`. Exclude only this endpoint from request forgery protection. Store unique delivery IDs, a SHA-256 fingerprint of the signed body, and minimal event metadata; never store raw payloads, signatures, or secrets. Do not trust the unsigned delivery/event headers alone: deduplicate repeated body fingerprints and validate push-specific payload fields before matching repository/branch. Handle only push events, match the exact configured repository and branch, ignore deleted branches, and queue deployments through the Run 07 action so existing readiness and concurrency rules apply. Do not create GitHub webhooks through the API, execute operating-system commands from the webhook request, or add deployment retry/rollback behavior.

## RUN 09 safety boundary

RUN 09 may enable HTTPS only for an active, provisioned Project whose domain status is active and whose generated domain still matches Settings. Laravel stays unprivileged and calls only the fixed Apache helper through non-interactive sudo. Pass the authenticated administrator's email as a validated argument; do not accept an arbitrary Certbot command or domain. The helper may invoke only fixed Certbot, OpenSSL, Apache, and systemd operations with argument arrays. It owns the exact TLS virtual host and HTTP redirect, verifies certificate hostname and expiry, and installs only a fixed Apache reload deploy hook. Do not install packages, use Cloudflare APIs or DNS plugins, run Certbot in a web request, or expose arbitrary command execution. Fake every Laravel process call; tests must not contact Let's Encrypt, DNS, Apache, or sudo.

## RUN 10 safety boundary

RUN 10 may check a deployed application only when its Project has an active Apache domain. Send a bounded HTTP request to `127.0.0.1` with the validated project domain as the `Host` header, and disable redirects so the check never depends on public DNS. A connection failure or HTTP 5xx fails the deployment; other HTTP responses count as an application response because a root route may legitimately return 404 or redirect. Skip the check before Apache reports the domain active. Fake all process and HTTP calls in tests; never contact a public application or production service. Keep retry manual through the existing Deploy now action. Do not add automatic retries, database rollback, release directories, or maintenance mode: deployments update the checkout in place and migrations can change the database schema.

## RUN 11 safety boundary

RUN 11's root-only installer supports Ubuntu 24.04 on amd64 and arm64 only. It may install fixed OS packages, clone only the configured HTTPS GitHub Manager repository, create the Manager database, configure Apache/PHP-FPM, install the existing root-owned helpers and exact sudo rules, write the Manager vhost, and install one queue worker unit. Laravel/PHP, Composer, npm, migrations, seeders, and the queue worker must run as `www-data`; never run application code as root. Keep the Manager source root-owned and writable runtime directories limited to `storage` and `bootstrap/cache`. The Manager itself uses PHP 8.3 and MySQL in production; do not ask the installer user to choose per-app runtimes or database engines. Detect the public IPv4 with a bounded request and use it for `APP_URL` unless `MANAGER_URL` is supplied. If UFW is already active, add only `ufw allow 8080/tcp`; do not enable UFW or change existing rules. On Ubuntu images using the supported Oracle `/etc/iptables/rules.v4` format, insert only an inbound TCP 8080 allow rule after the existing SSH rule, validate the full file with `iptables-restore --test`, restore it, and enable `netfilter-persistent`; preserve every other rule. Fail before installation for unsupported restrictive firewall formats. Never change SSH rules, provider/subnet firewall rules, remote MySQL access, Cloudflare DNS, or unrelated system services. Never execute the installer in local development or tests; tests may invoke only `scripts/install.sh --dry-run`, which must not modify the host. Before marking this RUN complete, smoke-test the full installer on a disposable Ubuntu 24.04 VPS.

## RUN 12 safety boundary — per-application PHP/database choices

Create App collects one of the fixed PHP versions 8.2, 8.3, or 8.4 and one of the fixed database engines MySQL or PostgreSQL per Project. The Settings PHP value may preselect a version; a Project's saved value is authoritative. Keep Laravel Manager on PHP 8.3/MySQL in production and SQLite in local development. Detect the selected PHP-FPM service/socket, database service, and matching PHP PDO driver. Show unavailable choices disabled with the missing requirement and repeat all checks during save; public Livewire properties are not trusted for availability.

The root-only Ubuntu 24.04 installer may add the signed Ondřej Surý PHP PPA and install the supported PHP-FPM versions, drivers, MySQL, and PostgreSQL. Never install packages or enable services from an HTTP request. Keep PHP versions and database engines allowlisted in Laravel validation, Apache sudoers, and the root-owned helpers. Apache uses the Project's selected PHP-FPM socket, including HTTPS. Deployment invokes the fixed PHP CLI binary and Composer under that Project's selected PHP version.

Laravel stays unprivileged. Provision an app database only through the root-owned `laravel-manager-database` helper with a validated slug and `mysql|pgsql` argument. MySQL uses the local root-authenticated socket; PostgreSQL uses the local `postgres` OS account. Give each app its own database/account with database-local privileges only. Write the random credential only to the project's `.env` with owner/mode preserved and verify the app connection. Never store the password in Project, render it in the UI, pass it in process arguments/environment, or log it. Fake helper/process calls in tests and never connect tests to MySQL or PostgreSQL.

## RUN 13 safety boundary — first-run setup

The installer creates the initial administrator; setup confirms that authenticated account and must not add public registration. Mark setup incomplete only when the initial admin seeder first creates its setting, and preserve a completed value on subsequent seeder runs. Existing servers without a setup marker remain usable. During setup, allow only the authenticated wizard, GitHub OAuth connect/callback, logout, and read-only fixed local requirement checks. Save only ordinary server settings; never install software, run privileged helpers, provision apps/databases, make DNS API/network checks, or modify Apache. GitHub is optional. Require the administrator to confirm wildcard DNS manually and describe it as a confirmation, not an automated verification. Until setup is complete, protect Apps, Server, and Settings routes. Keep all process calls fixed, local, and bounded.

## RUN 14 security boundary — hardening review

Keep the single-administrator trust model and document that the installer currently shares `www-data` between Manager, application PHP-FPM, and deployment scripts. Do not imply per-project isolation. Document that Manager port 8080 uses plain HTTP and recommend source-IP-restricted subnet ingress or a TLS reverse proxy before signing in. Preserve narrow root helpers and fixed command arrays; never add terminal access or user-controlled process arguments. Keep OAuth tokens hidden from serialization, lock model-backed Livewire state used by privileged actions, bound webhook request size, and redact secrets from stored process output. Review every `Process` and `subprocess.run` call, sudoers rule, and installer root operation when changing infrastructure code.

## RUN 15 safety boundary — Manager updates

Run Manager updates only through the root-owned `/usr/local/bin/laravel-manager update` CLI command, initiated by an administrator with `sudo` or by the fixed RUN 19 systemd service. The command accepts no user-provided command, path, repository, or branch; it follows the installed HTTPS GitHub origin and checked-out branch. Before rejecting a dirty checkout, it may restore executable mode only for a tracked file whose content hash still matches Git; all other local changes block the update. Use a root-owned incomplete-update marker so the same command can resume after a failed or interrupted update; serialize updates with a local lock. Run Laravel, Composer, and npm as `www-data`. Keep the updater engine out of HTTP requests and direct sudoers grants for `www-data`; RUN 19 permits only its narrow asynchronous launcher. Stop Apache, the queue, and every active supported PHP-FPM service during the brief update window; restore root ownership of Manager source and dependencies before services restart, then verify the local login endpoint. Never run Artisan, Composer, or npm as root.

## RUN 17 security boundary — create a GitHub repository from Create App

Create only a private repository owned by the connected administrator through GitHub REST `POST /user/repos`, using the existing OAuth `repo` scope and `auto_init=false`. Derive its name from the validated Project slug. Use `composer create-project` only for the official `laravel/laravel` package, pinned to Laravel 12 for PHP 8.2 or Laravel 13 for PHP 8.3/8.4, with `--no-install --no-scripts --no-plugins`; never install dependencies or execute starter/repository code in Create App. Use fixed Laravel Process argument arrays and bounded timeouts. Keep the OAuth token only in transient Git environment config for `git push`; never put it in arguments, URLs, `.git/config`, Project data, UI, or logs. Do not log raw process output. Stage only in a unique Manager-owned storage directory and remove that directory on all exits. If the repository exists but push/provisioning fails, retain its canonical metadata on the failed Project; do not automatically delete it. Do not add public repositories, arbitrary shell/URL inputs, webhooks, deployment access, or features from later RUNs.

## RUN 18 boundary — theme and Manager reverse proxy

Theme preference is browser-local (`localStorage`) and supports only system, light, and dark modes; do not add account/server persistence or a theme package. The Manager may trust forwarded proxy headers only from loopback `127.0.0.1`, with a fixed local Apache upstream. Never trust arbitrary remote forwarded headers or all proxy addresses. Manager panel TLS and firewall rules remain administrator-configured; do not add certificate or provider-firewall automation in this RUN.


## RUN 19 safety boundary — panel updates

Reuse the RUN 15 root CLI updater. The authenticated Settings component may invoke only fixed `status|check|start` argument arrays through `/usr/local/sbin/laravel-manager-updates`; exact sudoers entries must not authorize `run`, `progress`, arbitrary service names, commands, paths, repositories, branches, or commits. Execute the update in the independent root-owned `laravel-manager-update.service`, never an HTTP request or Manager queue job. Validate root ownership and safe paths in the bridge independently of Laravel; use a public credential-free HTTPS GitHub origin from the installed checkout.

Keep atomic root-owned status and bounded fixed phase history outside the checkout/database. Never expose raw subprocess diagnostics, environment contents, tokens, or passwords to HTTP. Bound checks and service launch calls; serialize service requests and actual CLI updates. Guard every infrastructure entry point with the shared operations lock, including repository creation, provisioning, domain/database/SSL operations, deployment requests, and deployment execution. Recheck pending/running database records under the root-exclusive gate before maintenance. Nested operations retain the outer lock; an accepted update blocks new operations. Laravel remains unprivileged.

Keep `.env`, keys, setup state, Manager/app data, permissions, and existing configuration intact. New installations include the bridge; existing installations receive it through the root CLI/bootstrap, without reinstalling. Source remains root-owned except runtime directories. Document shared-service downtime, the shared `www-data` trust model, and CLI recovery without promising automatic database rollback. Test the real service interruption/reconnection and interrupted-update recovery on the existing Ubuntu VM, using only isolated UI/database copies and reversible OS fixtures. Never publish unfinished source merely to test an update.


## RUN 20 safety boundary — local development and repository webhooks

Generate local commands only from canonical GitHub metadata and validated slug/branch, using shell quoting. Never include Manager tokens or server database credentials, execute these commands through HTTP, or overwrite a developer's environment. Keep initial setup and daily work distinct; customized repositories retain their README-specific requirements.

Configure hooks only through the existing GitHub OAuth connection and fixed repository REST operations after validating canonical repository metadata, administrator permissions and a public HTTPS Manager origin. Serialize configuration per repository with a bounded cache lock under InfrastructureLock. Bound listing pagination and HTTP timeouts, reconcile ambiguous create failures before another POST, reuse only compatible exact callbacks or recorded hooks with matching old identity, and preserve unrelated/conflicting hooks. Never accept a browser-supplied hook ID or disable TLS verification.

Reuse GITHUB_WEBHOOK_SECRET when set; otherwise generate one persistent random secret encrypted in an isolated AppSetting entry. The receiver and configurator share WebhookSecret. Never write .env from HTTP, expose secrets in serialization/UI/logs, or rotate them on retries/disconnection. Keep webhook errors separate from Project provisioning status. Only a valid raw-body signature and matching signed repository/hook ping may mark delivery verified; pings never deploy. Preserve legacy signed pushes, exact branch matching, deduplication and deployment locking. Fake all GitHub/process calls in automated tests. VM smoke tests use isolated application/database copies; public GitHub delivery requires authorized disposable resources and must not be claimed from simulations.

## RUN 21 safety boundary — initial publication and manual updates

Create App records and queues publication on the database connection; long repository, Composer/npm, database, Apache and Certbot operations never run in its HTTP request. Reuse existing actions and root helpers in ordered stages: repository, files, database, deployment, domain, local response check, HTTPS. Queue payloads contain IDs/version only. Claim authoritative persisted stages under InfrastructureLock and ignore duplicate/stale jobs. Manager update readiness includes queued/running publication. New apps default to manual; migration preserves existing automatic behavior. Only explicitly automatic Projects may queue from signed pushes, with the preference rechecked under the deployment row lock.

Retry resumes the failed stage and preserves files, databases, credentials and successful initial deployments. Never clone over a saved path, recreate an existing database, repeat an ambiguous repository POST, force-push, delete GitHub resources or promise migration rollback. Ready requires the initial deployment, Apache/local response and HTTPS. HTTPS failure preserves HTTP and reports the outstanding stage. Worker failure callbacks safely mark interrupted initial deployments failed; do not expose exception diagnostics or credentials in publication summaries.

Remove inherited Manager configuration/secrets from app subprocesses, preserving only OS variables and worker Composer/npm caches, plus explicit transient Git authorization for Git operations. Run every app command under the saved allowlisted PHP version as the unprivileged worker. Update APP_URL to HTTPS only in the queued job through a validated slug-derived path, regular non-symlink .env, atomic private temporary file, same owner and mode 0600; refresh configuration with the selected PHP CLI. Never write the Manager .env from HTTP.

The database helper may trust Git ownership only for its validated exact project directory with global/system config disabled and fsmonitor disabled; never safe.directory=*. The existing root updater bootstrap refreshes only fixed root-owned helper files and the fixed queue unit/cache directories. Preserve helper configuration, grants, server data and narrow sudo rules. Test real publication/manual updates on the existing VM with isolated apps/local Git remotes; label GitHub metadata and certificate issuance fixtures accurately. Automated Laravel tests fake all processes/HTTP; force in-memory SQLite and fixed test helper defaults independently of the VM environment.
