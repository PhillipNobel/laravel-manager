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

RUN 08 accepts GitHub webhooks only through the dedicated unauthenticated endpoint after validating `X-Hub-Signature-256` against the exact raw request body with `GITHUB_WEBHOOK_SECRET`. Exclude only this endpoint from request forgery protection. Store unique delivery IDs and minimal event metadata, never raw payloads, signatures, or secrets. Handle only push events, match the exact configured repository and branch, ignore deleted branches, and queue deployments through the Run 07 action so existing readiness and concurrency rules apply. Do not create GitHub webhooks through the API, execute operating-system commands from the webhook request, or add deployment retry/rollback behavior.

## RUN 09 safety boundary

RUN 09 may enable HTTPS only for an active, provisioned Project whose domain status is active and whose generated domain still matches Settings. Laravel stays unprivileged and calls only the fixed Apache helper through non-interactive sudo. Pass the authenticated administrator's email as a validated argument; do not accept an arbitrary Certbot command or domain. The helper may invoke only fixed Certbot, OpenSSL, Apache, and systemd operations with argument arrays. It owns the exact TLS virtual host and HTTP redirect, verifies certificate hostname and expiry, and installs only a fixed Apache reload deploy hook. Do not install packages, use Cloudflare APIs or DNS plugins, run Certbot in a web request, or expose arbitrary command execution. Fake every Laravel process call; tests must not contact Let's Encrypt, DNS, Apache, or sudo.

## RUN 10 safety boundary

RUN 10 may check a deployed application only when its Project has an active Apache domain. Send a bounded HTTP request to `127.0.0.1` with the validated project domain as the `Host` header, and disable redirects so the check never depends on public DNS. A connection failure or HTTP 5xx fails the deployment; other HTTP responses count as an application response because a root route may legitimately return 404 or redirect. Skip the check before Apache reports the domain active. Fake all process and HTTP calls in tests; never contact a public application or production service. Keep retry manual through the existing Deploy now action. Do not add automatic retries, database rollback, release directories, or maintenance mode: deployments update the checkout in place and migrations can change the database schema.

## RUN 11 safety boundary

RUN 11's root-only installer supports Ubuntu 24.04 on amd64 and arm64 only. It may install fixed OS packages, clone only the configured HTTPS GitHub Manager repository, create the Manager database, configure Apache/PHP-FPM, install the existing root-owned helpers and exact sudo rules, write the Manager vhost, and install one queue worker unit. Laravel/PHP, Composer, npm, migrations, seeders, and the queue worker must run as `www-data`; never run application code as root. Keep the Manager source root-owned and writable runtime directories limited to `storage` and `bootstrap/cache`. The Manager itself uses PHP 8.3 and MySQL in production; do not ask the installer user to choose per-app runtimes or database engines. Do not alter SSH, UFW/provider firewalls, remote MySQL access, Cloudflare DNS, or unrelated system services. Never execute the installer in local development or tests; tests may invoke only `scripts/install.sh --dry-run`, which must not modify the host. Before marking this RUN complete, smoke-test the full installer on a disposable Ubuntu 24.04 VPS.

## Approved per-application PHP/database direction (RUN 12 pending)

Create App will collect a PHP version (initial options: 8.2, 8.3, or 8.4) and a database engine (MySQL or PostgreSQL) for each Project. The Settings PHP value may preselect a version; the Project's saved value is authoritative. Laravel Manager itself stays on PHP 8.3 and uses MySQL in production; local development remains SQLite. Only offer runtimes and engines that are available on the server. Never install OS packages from an HTTP request. Implement this extension only in RUN 12 after it is explicitly requested; verify current PHP support and the safe Ubuntu package strategy when that RUN starts.
