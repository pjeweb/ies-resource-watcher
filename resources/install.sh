#!/bin/bash
# Install or update the watcher in attached mode for one channel (systemd, Debian-like host).
#
#   resources/install.sh CHANNEL CONFIG.json [--enable]
#
# Run as root from an unpacked copy of this package. Code goes to
# /usr/local/lib/ies-resource-watcher (shared by all channels on the host),
# the configuration to /etc/ies-resource-watcher/CHANNEL.json. Previous code,
# configuration, units and a queue snapshot are backed up; on failure they are
# restored and the previous service state is resumed. The queue is never reset.
set -euo pipefail

if [[ $(id -u) != 0 || $# -lt 2 || $# -gt 3 || ! ${3:-} =~ ^(--enable)?$ ]]; then
    echo "Usage (root): $0 CHANNEL CONFIG.json [--enable]" >&2
    exit 2
fi
channel=$1
[[ $channel =~ ^[a-z][a-z0-9-]*$ ]] || { echo "invalid channel name" >&2; exit 2; }
configuration=$(realpath "$2")
package=$(cd "$(dirname "$0")/.." && pwd)
prefix=/usr/local/lib/ies-resource-watcher
user=www-data # as in the units
service="ies-resource-watcher@$channel.service"
timer="ies-resource-watcher-health@$channel.timer"
state=/var/lib/ies-resource-watcher/$channel

php -r '
    $c = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
    $fail = fn($m) => fwrite(STDERR, $m . "\n") && exit(1);
    ($c["watcher"]["state_dir"] ?? null) === $argv[2] || $fail("watcher.state_dir must be " . $argv[2]);
    is_file(($c["project"] ?? "") . "/vendor/autoload.php") || $fail("project is not an installed application");
    is_dir($c["resource_root"] ?? "") || $fail("resource_root does not exist");
' "$configuration" "$state"
for file in "$package"/bin/ies-resource-watcher $(find "$package/src" -name '*.php'); do
    php -l "$file" >/dev/null
done

install -d -m 0700 /root/ies-resource-watcher-backups
backup=$(mktemp -d "/root/ies-resource-watcher-backups/$(date -u +%Y%m%dT%H%M%SZ)-$channel-XXXXXX")
was_active=false
was_enabled=false
systemctl is-active --quiet "$service" && was_active=true
systemctl is-enabled --quiet "$service" && was_enabled=true

rollback() {
    code=$?
    trap - ERR
    echo "Install failed; restoring the previous installation from $backup (queue retained)" >&2
    systemctl stop "$service" 2>/dev/null || true
    if [[ -d $backup/code ]]; then
        rm -rf "$prefix.failed" && mv "$prefix" "$prefix.failed" 2>/dev/null || true
        cp -a "$backup/code" "$prefix"
    fi
    while IFS= read -r -d '' saved; do
        cp -a "$saved" "${saved#"$backup"}"
    done < <(find "$backup/etc" -type f -print0 2>/dev/null)
    systemctl daemon-reload
    if ! $was_enabled; then
        systemctl disable "$service" "$timer" 2>/dev/null || true
    fi
    if $was_active; then
        systemctl reset-failed "$service" 2>/dev/null || true
        systemctl start "$service" || true
    fi
    exit "$code"
}
save_previous() {
    if [[ -f $1 ]]; then
        install -d -m 0755 "$backup$(dirname "$1")"
        cp -a "$1" "$backup$1"
    fi
}
trap rollback ERR

systemctl stop "$service" 2>/dev/null || true
[[ -d $prefix ]] && cp -a "$prefix" "$backup/code"
save_previous "/etc/ies-resource-watcher/$channel.json"
for unit in ies-resource-watcher@.service ies-resource-watcher-health@.service ies-resource-watcher-health@.timer; do
    save_previous "/etc/systemd/system/$unit"
done
if [[ -f $state/queue.sqlite ]]; then
    php -r '$s = new PDO("sqlite:" . $argv[1]); $s->exec("VACUUM INTO " . $s->quote($argv[2]));' "$state/queue.sqlite" "$backup/queue.sqlite"
fi

# Code: only what attached mode needs, root-owned and read-only for the service.
staging=$(mktemp -d "$prefix.new.XXXXXX")
cp -a "$package/bin" "$package/src" "$package/resources" "$package/README.md" "$package/LICENSE" "$package/composer.json" "$staging/"
chown -R root:root "$staging"
chmod -R u=rwX,go=rX "$staging"
rm -rf "$prefix.old"
[[ -d $prefix ]] && mv "$prefix" "$prefix.old"
mv "$staging" "$prefix"
rm -rf "$prefix.old"

install -d -m 0755 /etc/ies-resource-watcher
install -o root -g root -m 0644 "$configuration" "/etc/ies-resource-watcher/$channel.json.new"
mv "/etc/ies-resource-watcher/$channel.json.new" "/etc/ies-resource-watcher/$channel.json"
for unit in ies-resource-watcher@.service ies-resource-watcher-health@.service ies-resource-watcher-health@.timer; do
    install -o root -g root -m 0644 "$package/resources/systemd/$unit" "/etc/systemd/system/$unit"
done
systemd-analyze verify /etc/systemd/system/ies-resource-watcher@.service /etc/systemd/system/ies-resource-watcher-health@.service /etc/systemd/system/ies-resource-watcher-health@.timer
systemctl daemon-reload
install -d -o "$user" -g "$user" -m 0750 "$state"

# Boots the application with the bundle, compiles the container, reaches the index and the native lock.
runuser -u "$user" -- php "$prefix/bin/ies-resource-watcher" "/etc/ies-resource-watcher/$channel.json" ies-resource-watcher:check

if [[ ${3:-} == --enable ]]; then
    systemctl enable --now "$service" "$timer"
elif $was_active; then
    systemctl start "$service"
fi
trap - ERR
echo "Installed $channel; previous installation and queue snapshot: $backup"
