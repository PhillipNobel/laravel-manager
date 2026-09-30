#!/usr/bin/env bash

set -Eeuo pipefail
umask 027

readonly APP_DIR='/opt/laravel-manager'
readonly APP_DATABASE='laravel_manager'
readonly APP_DB_USER='laravel_manager'
readonly APPLICATIONS_DIR='/var/www/apps'
readonly NODE_MAJOR='24'
readonly INSTALL_MARKER='/var/lib/laravel-manager/installed'

INSTALL_TMP=''
ADMIN_EMAIL=''
ADMIN_PASSWORD=''
MANAGER_URL="${MANAGER_URL:-}"

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

handle_error() {
    local status="$1"
    local line="$2"

    printf 'ERROR: installer failed at line %s (exit %s). Review the output; completed package or service changes are not rolled back.\n' "$line" "$status" >&2
    exit "$status"
}

cleanup() {
    if [[ -n "$INSTALL_TMP" && -d "$INSTALL_TMP" ]]; then
        case "$INSTALL_TMP" in
            /tmp/laravel-manager-install.*) /bin/rm -rf -- "$INSTALL_TMP" ;;
        esac
    fi
}

trap 'handle_error "$?" "$LINENO"' ERR
trap cleanup EXIT

usage() {
    cat <<'USAGE'
Usage: install.sh [--dry-run | --help]

Required environment:
  LARAVEL_MANAGER_REPOSITORY  Public HTTPS GitHub repository URL

Optional environment:
  LOCAL_ADMIN_EMAIL           Seed the first administrator without a prompt
  LOCAL_ADMIN_PASSWORD       Password for the first administrator (16-72 bytes)
  MANAGER_URL                Browser-reachable manager URL (default: first local IPv4 on port 8080)

With no option, install Laravel Manager on a clean Ubuntu 24.04 VPS as root.
USAGE
}

valid_repository_url() {
    local repository_pattern='^https://github\.com/[A-Za-z0-9]([A-Za-z0-9-]{0,38}[A-Za-z0-9])?/[A-Za-z0-9]([A-Za-z0-9._-]{0,98}[A-Za-z0-9])?(\.git)?$'

    [[ "$1" =~ $repository_pattern ]]
}

show_plan() {
    local planned_manager_url="${MANAGER_URL:-http://SERVER_IP:8080}"

    if [[ -n "$MANAGER_URL" ]]; then
        validate_manager_url "$MANAGER_URL" \
            || fail 'MANAGER_URL must be an HTTP or HTTPS URL with a hostname or IPv4 address and optional port.'
    fi

    printf '%s\n' \
        'Dry run; no system changes will be made.' \
        'Target: Ubuntu 24.04 LTS (amd64 or arm64)' \
        "Repository: $LARAVEL_MANAGER_REPOSITORY" \
        'Install directory: /opt/laravel-manager' \
        'PHP-FPM: 8.2, 8.3, and 8.4 (ppa:ondrej/php)' \
        'Node.js: 24 LTS' \
        'Databases: MySQL and PostgreSQL' \
        'Packages: Apache, MySQL, PostgreSQL, Git, Composer, Certbot, PHP runtimes and drivers, Node.js/npm' \
        'Manager database: laravel_manager (local MySQL socket)' \
        'Queue: systemd service running as www-data' \
        'Updates: sudo laravel-manager update; version: sudo laravel-manager version' \
        "Manager URL: $planned_manager_url" \
        'Application directory: /var/www/apps' \
        'Firewall: keep Manager port 8080 private or source-IP restricted; expose ports 80/443 as needed for managed sites.'
}

validate_manager_url() {
    local manager_url_pattern='^https?://([A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?)(:([0-9]{1,5}))?$'

    [[ "$1" =~ $manager_url_pattern ]] || return 1

    local port="${BASH_REMATCH[4]:-}"
    [[ -z "$port" ]] || (( 10#$port >= 1 && 10#$port <= 65535 ))
}

resolve_manager_url() {
    if [[ -z "$MANAGER_URL" ]]; then
        local server_ip
        server_ip=$(/usr/bin/hostname -I | /usr/bin/awk '{print $1}')
        [[ -n "$server_ip" ]] || fail 'Cannot detect a server IPv4 address. Set MANAGER_URL to the browser-reachable manager URL.'
        MANAGER_URL="http://${server_ip}:8080"
    fi

    validate_manager_url "$MANAGER_URL" \
        || fail 'MANAGER_URL must be an HTTP or HTTPS URL with a hostname or IPv4 address and optional port.'
}

require_repository() {
    if [[ -z "${LARAVEL_MANAGER_REPOSITORY:-}" ]]; then
        fail 'Set LARAVEL_MANAGER_REPOSITORY to the public HTTPS GitHub repository URL.'
    fi

    if ! valid_repository_url "$LARAVEL_MANAGER_REPOSITORY"; then
        fail 'LARAVEL_MANAGER_REPOSITORY must be an HTTPS GitHub repository URL.'
    fi
}

require_root_and_platform() {
    [[ "$EUID" -eq 0 ]] || fail 'Run this installer as root, for example with sudo.'

    [[ -r /etc/os-release ]] || fail 'Cannot identify the operating system; Ubuntu 24.04 LTS is required.'
    # shellcheck disable=SC1091
    . /etc/os-release

    [[ "${ID:-}" == 'ubuntu' && "${VERSION_ID:-}" == '24.04' ]] \
        || fail 'This installer supports Ubuntu 24.04 LTS only.'

    local architecture
    architecture=$(/usr/bin/dpkg --print-architecture)
    case "$architecture" in
        amd64|arm64) ;;
        *) fail "Unsupported architecture: $architecture. Use amd64 or arm64." ;;
    esac
}

require_clean_target() {
    if [[ -e "$INSTALL_MARKER" ]]; then
        printf 'Laravel Manager is already installed. Marker: %s\n' "$INSTALL_MARKER"
        exit 0
    fi

    local path
    for path in \
        "$APP_DIR" \
        "$APPLICATIONS_DIR" \
        /etc/laravel-manager \
        /etc/apache2/sites-available/laravel-manager.conf \
        /etc/apache2/sites-enabled/laravel-manager.conf \
        /etc/apache2/conf-available/laravel-manager-port.conf \
        /etc/systemd/system/laravel-manager-queue.service \
        /etc/sudoers.d/laravel-manager-apache \
        /etc/sudoers.d/laravel-manager-database \
        /usr/local/sbin/laravel-manager-apache \
        /usr/local/sbin/laravel-manager-database \
        /usr/local/bin/laravel-manager \
        /etc/apt/sources.list.d/nodesource.sources \
        /etc/apt/sources.list.d/ondrej-ubuntu-php-noble.sources \
        /etc/apt/preferences.d/laravel-manager-nodejs \
        /usr/share/keyrings/nodesource.gpg \
        /var/cache/laravel-manager \
        /var/lib/laravel-manager; do
        if [[ -e "$path" || -L "$path" ]]; then
            fail "Existing path prevents a clean installation: $path. Review it before retrying."
        fi
    done
}

read_admin_credentials() {
    local LC_ALL=C

    if [[ -n "${LOCAL_ADMIN_EMAIL:-}" || -n "${LOCAL_ADMIN_PASSWORD:-}" ]]; then
        [[ -n "${LOCAL_ADMIN_EMAIL:-}" && -n "${LOCAL_ADMIN_PASSWORD:-}" ]] \
            || fail 'Set both LOCAL_ADMIN_EMAIL and LOCAL_ADMIN_PASSWORD, or leave both unset for a prompt.'
        ADMIN_EMAIL="$LOCAL_ADMIN_EMAIL"
        ADMIN_PASSWORD="$LOCAL_ADMIN_PASSWORD"
    else
        [[ -r /dev/tty ]] || fail 'A terminal is required to enter initial administrator credentials.'

        printf 'Administrator email: ' > /dev/tty
        IFS= read -r ADMIN_EMAIL < /dev/tty || fail 'Could not read the administrator email.'
        printf 'Administrator password (16-72 bytes): ' > /dev/tty
        IFS= read -r -s ADMIN_PASSWORD < /dev/tty || fail 'Could not read the administrator password.'
        printf '\nConfirm administrator password: ' > /dev/tty
        local password_confirmation
        IFS= read -r -s password_confirmation < /dev/tty || fail 'Could not confirm the administrator password.'
        printf '\n' > /dev/tty
        [[ "$ADMIN_PASSWORD" == "$password_confirmation" ]] || fail 'Administrator passwords do not match.'
        unset password_confirmation
    fi

    [[ "$ADMIN_EMAIL" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] \
        || fail 'Enter a valid administrator email address.'
    [[ ${#ADMIN_PASSWORD} -ge 16 && ${#ADMIN_PASSWORD} -le 72 ]] \
        || fail 'Administrator password must contain 16 to 72 bytes.'
}

install_nodesource_repository() {
    /usr/bin/install -d -o root -g root -m 0755 /usr/share/keyrings
    /usr/bin/curl --fail --silent --show-error --location \
        https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
        --output "$INSTALL_TMP/nodesource.asc"
    /usr/bin/gpg --batch --yes --dearmor \
        --output "$INSTALL_TMP/nodesource.gpg" "$INSTALL_TMP/nodesource.asc"
    /usr/bin/install -o root -g root -m 0644 \
        "$INSTALL_TMP/nodesource.gpg" /usr/share/keyrings/nodesource.gpg

    cat > "$INSTALL_TMP/nodesource.sources" <<EOF
Types: deb
URIs: https://deb.nodesource.com/node_${NODE_MAJOR}.x
Suites: nodistro
Components: main
Architectures: $(/usr/bin/dpkg --print-architecture)
Signed-By: /usr/share/keyrings/nodesource.gpg
EOF
    /usr/bin/install -o root -g root -m 0644 \
        "$INSTALL_TMP/nodesource.sources" /etc/apt/sources.list.d/nodesource.sources

    cat > "$INSTALL_TMP/nodejs.pref" <<'EOF'
Package: nodejs
Pin: origin deb.nodesource.com
Pin-Priority: 600
EOF
    /usr/bin/install -o root -g root -m 0644 \
        "$INSTALL_TMP/nodejs.pref" /etc/apt/preferences.d/laravel-manager-nodejs
}

install_system_packages() {
    local version
    local php_packages=()
    local php_fpm_services=()

    /usr/bin/apt-get update
    /usr/bin/apt-get install -y ca-certificates curl gnupg software-properties-common
    LC_ALL=C.UTF-8 /usr/bin/add-apt-repository --yes ppa:ondrej/php
    install_nodesource_repository
    /usr/bin/apt-get update

    for version in 8.2 8.3 8.4; do
        php_packages+=(
            "php${version}-bcmath"
            "php${version}-cli"
            "php${version}-curl"
            "php${version}-fpm"
            "php${version}-intl"
            "php${version}-mbstring"
            "php${version}-mysql"
            "php${version}-opcache"
            "php${version}-pgsql"
            "php${version}-xml"
            "php${version}-zip"
        )
        php_fpm_services+=("php${version}-fpm.service")
    done

    /usr/bin/apt-get install -y \
        apache2 \
        certbot \
        git \
        mysql-server \
        nodejs \
        openssl \
        postgresql \
        "${php_packages[@]}" \
        python3 \
        python3-certbot-apache \
        sudo \
        unzip

    [[ "$(/usr/bin/node --version)" =~ ^v24\. ]] || fail 'NodeSource did not install Node.js 24.'
    /usr/bin/systemctl enable --now mysql.service postgresql.service "${php_fpm_services[@]}" apache2.service
}

install_composer() {
    local expected_checksum
    local actual_checksum

    expected_checksum=$(/usr/bin/curl --fail --silent --show-error --location https://composer.github.io/installer.sig)
    [[ "$expected_checksum" =~ ^[0-9a-fA-F]{96}$ ]] || fail 'Composer installer checksum is unavailable or invalid.'

    /usr/bin/curl --fail --silent --show-error --location \
        https://getcomposer.org/installer --output "$INSTALL_TMP/composer-setup.php"
    actual_checksum=$(/usr/bin/sha384sum "$INSTALL_TMP/composer-setup.php" | /usr/bin/awk '{print $1}')
    [[ "$actual_checksum" == "$expected_checksum" ]] || fail 'Composer installer checksum verification failed.'

    /usr/bin/php8.3 "$INSTALL_TMP/composer-setup.php" \
        --quiet --install-dir=/usr/local/bin --filename=composer
    /bin/rm -f -- "$INSTALL_TMP/composer-setup.php"
}

create_manager_database() {
    local schema_exists
    local account_exists
    local database_password

    schema_exists=$(/usr/bin/mysql --protocol=socket --user=root --batch --skip-column-names \
        --execute="SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '${APP_DATABASE}'")
    account_exists=$(/usr/bin/mysql --protocol=socket --user=root --batch --skip-column-names \
        --execute="SELECT COUNT(*) FROM mysql.user WHERE User = '${APP_DB_USER}' AND Host = 'localhost'")

    [[ "$schema_exists" == '0' && "$account_exists" == '0' ]] \
        || fail 'The Laravel Manager database or MySQL account already exists. Review MySQL before continuing.'

    database_password=$(/usr/bin/openssl rand -hex 32)
    /usr/bin/mysql --protocol=socket --user=root <<SQL
CREATE DATABASE ${APP_DATABASE} CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER '${APP_DB_USER}'@'localhost' IDENTIFIED BY '${database_password}';
GRANT ALL PRIVILEGES ON ${APP_DATABASE}.* TO '${APP_DB_USER}'@'localhost';
SQL

    /usr/bin/install -o www-data -g www-data -m 0600 \
        "$APP_DIR/.env.example" "$APP_DIR/.env"
    /usr/bin/sed -i \
        -e 's/^APP_ENV=.*/APP_ENV=production/' \
        -e 's/^APP_DEBUG=.*/APP_DEBUG=false/' \
        -e "s#^APP_URL=.*#APP_URL=${MANAGER_URL}#" \
        -e 's/^DB_CONNECTION=.*/DB_CONNECTION=mysql/' \
        -e 's/^MANAGER_DEFAULT_PHP_VERSION=.*/MANAGER_DEFAULT_PHP_VERSION=8.3/' \
        "$APP_DIR/.env"
    cat >> "$APP_DIR/.env" <<EOF

# Local production database configured by the installer.
DB_HOST=localhost
DB_PORT=3306
DB_SOCKET=/var/run/mysqld/mysqld.sock
DB_DATABASE=${APP_DATABASE}
DB_USERNAME=${APP_DB_USER}
DB_PASSWORD=${database_password}
EOF
    unset database_password
    /usr/bin/chown www-data:www-data "$APP_DIR/.env"
    /usr/bin/chmod 0600 "$APP_DIR/.env"
}

install_application() {
    /usr/bin/git clone --depth=1 "$LARAVEL_MANAGER_REPOSITORY" "$APP_DIR"
    /usr/bin/chown -R www-data:www-data "$APP_DIR"
    /usr/bin/find "$APP_DIR" -type d -exec /bin/chmod 0750 {} +
    /usr/bin/find "$APP_DIR" -type f ! -perm /111 -exec /bin/chmod 0640 {} +
    /usr/bin/find "$APP_DIR" -type f -perm /111 -exec /bin/chmod 0750 {} +
    /usr/bin/chmod 0750 "$APP_DIR/artisan"

    /usr/bin/install -d -o www-data -g www-data -m 0770 \
        "$APP_DIR/storage/framework/cache/data" \
        "$APP_DIR/storage/framework/sessions" \
        "$APP_DIR/storage/framework/views" \
        "$APP_DIR/storage/logs" \
        "$APP_DIR/bootstrap/cache" \
        /var/cache/laravel-manager/composer \
        /var/cache/laravel-manager/npm

    create_manager_database

    cd "$APP_DIR"
    /usr/sbin/runuser -u www-data -- env \
        HOME=/var/www \
        COMPOSER_HOME=/var/cache/laravel-manager/composer \
        /usr/bin/php8.3 /usr/local/bin/composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
    /usr/sbin/runuser -u www-data -- env \
        HOME=/var/www \
        npm_config_cache=/var/cache/laravel-manager/npm \
        /usr/bin/npm ci --no-audit --no-fund
    /usr/sbin/runuser -u www-data -- env \
        HOME=/var/www \
        npm_config_cache=/var/cache/laravel-manager/npm \
        /usr/bin/npm run build
    /usr/sbin/runuser -u www-data -- env \
        HOME=/var/www \
        /usr/bin/php8.3 artisan key:generate --force
    /usr/sbin/runuser -u www-data -- /usr/bin/php8.3 artisan migrate --force

    export LOCAL_ADMIN_NAME='Laravel Manager Admin'
    export LOCAL_ADMIN_EMAIL="$ADMIN_EMAIL"
    export LOCAL_ADMIN_PASSWORD="$ADMIN_PASSWORD"
    /usr/sbin/runuser -u www-data --preserve-environment -- /usr/bin/php8.3 \
        artisan db:seed --class=AdminUserSeeder --force
    unset LOCAL_ADMIN_NAME LOCAL_ADMIN_EMAIL LOCAL_ADMIN_PASSWORD ADMIN_PASSWORD

    /usr/sbin/runuser -u www-data -- /usr/bin/php8.3 artisan optimize
    /usr/bin/chown -R root:www-data "$APP_DIR"
    /usr/bin/find "$APP_DIR" -type d -exec /bin/chmod 0750 {} +
    /usr/bin/find "$APP_DIR" -type f ! -perm /111 -exec /bin/chmod 0640 {} +
    /usr/bin/find "$APP_DIR" -type f -perm /111 -exec /bin/chmod 0750 {} +
    /usr/bin/chmod 0750 "$APP_DIR/artisan"
    /usr/bin/chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
    /usr/bin/find "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" -type d -exec /bin/chmod 0770 {} +
    /usr/bin/find "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" -type f -exec /bin/chmod 0660 {} +
    /usr/bin/chown root:www-data "$APP_DIR/.env"
    /usr/bin/chmod 0640 "$APP_DIR/.env"
}

install_manager_command() {
    /usr/bin/install -o root -g root -m 0755 \
        "$APP_DIR/scripts/laravel-manager" /usr/local/bin/laravel-manager
}

install_helpers_and_configuration() {
    /usr/bin/install -d -o root -g root -m 0755 /etc/laravel-manager
    /usr/bin/install -o root -g root -m 0755 \
        "$APP_DIR/scripts/laravel-manager-apache" /usr/local/sbin/laravel-manager-apache
    /usr/bin/install -o root -g root -m 0755 \
        "$APP_DIR/scripts/laravel-manager-database" /usr/local/sbin/laravel-manager-database
    /usr/bin/install -o root -g root -m 0644 \
        "$APP_DIR/scripts/laravel-manager-apache.json.example" /etc/laravel-manager/apache.json
    /usr/bin/install -o root -g root -m 0644 \
        "$APP_DIR/scripts/laravel-manager-database.json.example" /etc/laravel-manager/database.json
    /usr/bin/install -o root -g root -m 0440 \
        "$APP_DIR/scripts/laravel-manager-apache.sudoers" /etc/sudoers.d/laravel-manager-apache
    /usr/bin/install -o root -g root -m 0440 \
        "$APP_DIR/scripts/laravel-manager-database.sudoers" /etc/sudoers.d/laravel-manager-database
    /usr/sbin/visudo -cf /etc/sudoers.d/laravel-manager-apache
    /usr/sbin/visudo -cf /etc/sudoers.d/laravel-manager-database
}

configure_apache() {
    /usr/bin/install -o root -g root -m 0644 \
        "$APP_DIR/scripts/laravel-manager-port.conf" /etc/apache2/conf-available/laravel-manager-port.conf
    /usr/bin/install -o root -g root -m 0644 \
        "$APP_DIR/scripts/laravel-manager-vhost.conf" /etc/apache2/sites-available/laravel-manager.conf
    /usr/sbin/a2enmod rewrite proxy_fcgi setenvif
    /usr/sbin/a2enconf laravel-manager-port
    /usr/sbin/a2ensite laravel-manager
    /usr/bin/install -d -o www-data -g www-data -m 0750 "$APPLICATIONS_DIR"
    /usr/sbin/apache2ctl configtest
    /usr/bin/systemctl reload apache2.service
}

install_queue_service() {
    /usr/bin/install -o root -g root -m 0644 \
        "$APP_DIR/scripts/laravel-manager-queue.service" /etc/systemd/system/laravel-manager-queue.service
    /usr/bin/systemctl daemon-reload
    /usr/bin/systemctl enable --now laravel-manager-queue.service

    if /usr/bin/systemctl list-unit-files --type=timer --no-legend certbot.timer \
        | /usr/bin/grep -q '^certbot.timer'; then
        /usr/bin/systemctl enable --now certbot.timer
    fi
}

verify_installation() {
    local attempt

    for attempt in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do
        if /usr/bin/curl --fail --silent --show-error --output /dev/null http://127.0.0.1:8080/login; then
            break
        fi
        if [[ "$attempt" -eq 15 ]]; then
            fail 'Laravel Manager did not return its login page on 127.0.0.1:8080. Check Apache and PHP-FPM logs.'
        fi
        /bin/sleep 2
    done

    /usr/bin/install -d -o root -g root -m 0755 /var/lib/laravel-manager
    printf 'installed\n' > "$INSTALL_TMP/installed"
    /usr/bin/install -o root -g root -m 0644 \
        "$INSTALL_TMP/installed" "$INSTALL_MARKER"
}

main() {
    case "${1:-}" in
        --help|-h)
            usage
            return 0
            ;;
        --dry-run)
            require_repository
            show_plan
            return 0
            ;;
        '') ;;
        *) usage >&2; fail 'Unknown installer option.' ;;
    esac

    require_repository
    require_root_and_platform
    resolve_manager_url
    require_clean_target
    read_admin_credentials

    INSTALL_TMP=$(/usr/bin/mktemp -d /tmp/laravel-manager-install.XXXXXX)
    install_system_packages
    install_composer
    install_application
    install_manager_command
    install_helpers_and_configuration
    configure_apache
    install_queue_service
    verify_installation

    printf '\nLaravel Manager installed successfully.\n'
    printf 'Open: %s\n' "$MANAGER_URL"
    printf 'Administrator: %s\n' "$ADMIN_EMAIL"
    printf 'Security: port 8080 is plain HTTP. Keep it private and use an SSH tunnel or TLS reverse proxy before signing in.\n'
    printf 'Allow TCP ports 80 and 443 for managed sites as needed.\n'
}

main "$@"
