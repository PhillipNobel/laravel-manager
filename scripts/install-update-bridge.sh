#!/usr/bin/env bash
set -Eeuo pipefail
[[ "$EUID" -eq 0 && "$#" -eq 0 ]] || exit 1
readonly APP_DIR='/opt/laravel-manager'
for source in laravel-manager-updates laravel-manager-update.service laravel-manager-updates.sudoers laravel-manager-updates.tmpfiles; do
    path="$APP_DIR/scripts/$source"
    [[ -f "$path" && ! -L "$path" && "$(/usr/bin/stat -c %u "$path")" == 0 ]] || exit 1
    [[ "$(/usr/bin/stat -c %a "$path")" =~ ^(640|644|750|755)$ ]] || exit 1
done
/usr/bin/install -d -o root -g www-data -m 0750 /var/lib/laravel-manager/updates
/usr/bin/install -o root -g root -m 0755 "$APP_DIR/scripts/laravel-manager-updates" /usr/local/sbin/laravel-manager-updates
/usr/bin/install -o root -g root -m 0644 "$APP_DIR/scripts/laravel-manager-update.service" /etc/systemd/system/laravel-manager-update.service
/usr/bin/install -o root -g root -m 0644 "$APP_DIR/scripts/laravel-manager-updates.tmpfiles" /etc/tmpfiles.d/laravel-manager-updates.conf
/usr/sbin/visudo -cf "$APP_DIR/scripts/laravel-manager-updates.sudoers"
/usr/bin/install -o root -g root -m 0440 "$APP_DIR/scripts/laravel-manager-updates.sudoers" /etc/sudoers.d/laravel-manager-updates
/usr/bin/systemd-tmpfiles --create /etc/tmpfiles.d/laravel-manager-updates.conf
/usr/bin/systemctl daemon-reload
