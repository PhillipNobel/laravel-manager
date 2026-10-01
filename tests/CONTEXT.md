# Test Context

## RUN 21 initial publication and manual updates

Fake every GitHub, Process and HTTP call in automated Laravel tests. Exercise the queued coordinator one stage at a time, with ID/version-only payloads, real temporary private .env files and faked deployment commands. Assert ordering, final URL/owner/mode/secret preservation, failure/interruption/retry, stale job suppression, duplicate request blocking, manual signed push filtering, automatic opt-in, legacy backfill and Manager update locking. Child command environments must remove Manager settings/secrets while preserving OS/cache variables and explicit Git-only credentials. Migrate before clearing database-backed caches on the first deploy. phpunit.xml pins in-memory SQLite and fixed helper/domain/root test defaults even on the VM.

Python helper regression tests mock subprocesses and use temporary directories only; never contact MySQL or invoke a root helper in the suite. VM smoke verification separately uses isolated application/database copies, controlled local Git remotes, fixed temporary sudoers and local test certificate issuance. Run actual Composer/npm/PHP/MySQL/Apache there as the intended users, preserve installed Manager data and distinguish real HTTP/TLS serving from simulated GitHub/Let's Encrypt issuance. Verify fixed updater bootstrap file installation into isolated destinations; never publish unfinished source just to run an update.

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


## RUN 20 local guide and webhook setup

Test canonical command generation, safe shell quoting, local SQLite guidance and copy fallback markup; browser checks cover copy feedback and Clipboard API fallback. Fake all GitHub calls, bounded hook listing, creation/update/ping, administration checks, conflicts, lost responses and rate limits. Keep signing secrets encrypted/hidden and independent of GitHubConnection. Signed receiver tests cover matching/mismatched pings without deployment and reuse the existing push suite for branch/readiness/deduplication. No test may create a public GitHub resource, use live credentials or run real deployment processes. Settings tests must derive configured defaults from config rather than a developer-specific .env.
