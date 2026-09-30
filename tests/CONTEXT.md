# Test Context

## RUN 13 first-run setup

Setup route tests authenticate the existing administrator, check incomplete/completed routing, validate server defaults, and protect setup progress. Fake every local Process check; never install software, call DNS/GitHub from the tests, or invoke infrastructure helpers. GitHub is optional and the wildcard DNS step records the administrator's manual confirmation only.

## RUN 12 per-application runtime and database choices

Feature tests fake the fixed PHP CLI, PHP-FPM service/socket, database service, and PHP PDO-driver checks through Laravel's Process facade. Cover missing PHP versions, inactive database services, missing matching PDO drivers, visible disabled options, and server-side rejection of stale choices. Fake all database helper calls. Never install packages, start services, or connect tests to MySQL or PostgreSQL.

## RUN 11 installer seam

Installer behavior is tested only through the public `scripts/install.sh --dry-run` command. It validates the configured HTTPS GitHub repository and prints the planned Ubuntu setup without requiring root or changing the host. Feature tests must not run apt, download software, clone repositories, create databases, write system configuration, or start/reload services.

Use `bash -n` for shell syntax. Before marking RUN 11 complete, verify full package and service installation on a disposable Ubuntu 24.04 VPS; this cannot be established by local tests.
