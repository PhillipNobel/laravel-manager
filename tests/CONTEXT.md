# Test Context

## RUN 13 first-run setup

Setup route tests authenticate the existing administrator, check incomplete/completed routing, validate server defaults, and protect setup progress. Fake every local Process check; never install software, call DNS/GitHub from the tests, or invoke infrastructure helpers. GitHub is optional and the wildcard DNS step records the administrator's manual confirmation only.

## RUN 12 per-application runtime and database choices

Feature tests fake the fixed PHP CLI, PHP-FPM service/socket, database service, and PHP PDO-driver checks through Laravel's Process facade. Cover missing PHP versions, inactive database services, missing matching PDO drivers, visible disabled options, and server-side rejection of stale choices. Fake all database helper calls. Never install packages, start services, or connect tests to MySQL or PostgreSQL.

## RUN 11 installer seam

Installer behavior is tested only through the public `scripts/install.sh --dry-run` command. It validates the configured HTTPS GitHub repository and prints the planned Ubuntu setup without requiring root or changing the host. Feature tests must not run apt, download software, clone repositories, create databases, write system configuration, or start/reload services.

Use `bash -n` for shell syntax. Before marking RUN 11 complete, verify full package and service installation on a disposable Ubuntu 24.04 VPS; this cannot be established by local tests.

## RUN 17 GitHub repository creation

Feature tests cover Create App with either an existing repository or a newly created private repository. Fake all GitHub HTTP requests and Laravel Process calls, including Composer scaffolding, Git init/commit/push, and the subsequent clone. Assert repository ownership/visibility/URLs, selected PHP to Laravel major mapping, fixed main branch, private API request body, bounded process configuration, token-only transient Git environment use, no credential in arguments/config/logs, staging cleanup, and safe failure behavior. Never create a real GitHub repository or use an administrator's live OAuth token in tests.

## RUN 18 theme and Manager reverse proxy

Theme tests cover the authenticated header's System/Light/Dark choices and the early preference bootstrap. Forwarded HTTPS tests use a temporary route and fixed server variables: trust `X-Forwarded-Proto` only when `REMOTE_ADDR` is the loopback Apache proxy, and ignore it from a remote client. Do not contact a public Manager URL, change firewall rules, or request a certificate in tests.
