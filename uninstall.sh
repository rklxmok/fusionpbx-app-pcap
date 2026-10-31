#!/bin/sh
# Packet Capture — FusionPBX app uninstaller.
#
# Reverses install.sh: stops any running capture, removes the app files,
# the v_pcap_captures table, the menu item, the permissions, the default
# settings, the tcpdump file capabilities, and the php-fpm AF_PACKET
# systemd drop-in.
#
# Capture files are KEPT unless --remove-files is passed.
#
# Usage: sudo ./uninstall.sh [--remove-files]
set -eu

FUSIONPBX_DIR="${FUSIONPBX_DIR:-/var/www/fusionpbx}"
WEB_USER="${WEB_USER:-www-data}"
CAPTURE_DIR="${CAPTURE_DIR:-/var/lib/fusionpbx/pcap}"
TCPDUMP="$(command -v tcpdump || true)"
APP_DIR="$(cd "$(dirname "$0")" && pwd)"

if [ "$(id -u)" != 0 ]; then
	echo "run as root (sudo ./uninstall.sh)"; exit 1
fi
if [ ! -d "$FUSIONPBX_DIR" ]; then
	echo "FusionPBX not found at $FUSIONPBX_DIR (set FUSIONPBX_DIR=...)"; exit 1
fi

REMOVE_FILES=0
for arg in "$@"; do
	case "$arg" in
		--remove-files)	REMOVE_FILES=1 ;;
		*)	echo "unknown option: $arg"; exit 1 ;;
	esac
done

echo "== Packet Capture uninstaller =="

# 1. stop running captures + remove database objects. Uses FusionPBX's own
#    database abstraction (config from /etc/fusionpbx or install defaults)
#    so no credentials are parsed here. Runs as the web user: the capture
#    processes belong to it, so the kill signals are legal.
export FUSIONPBX_DIR
runuser -u "$WEB_USER" -- php <<'PHPEOF'
<?php
$fp = getenv("FUSIONPBX_DIR");
$_SERVER["DOCUMENT_ROOT"] = $fp;
$_SERVER["PROJECT_ROOT"] = $fp;
require_once $fp."/resources/require.php";
$database = new database;

// stop any running capture (same signal pcap_stop uses)
$rows = $database->select("SELECT pcap_pid FROM v_pcap_captures WHERE pcap_status = 'running'", null, 'all') ?: [];
foreach ($rows as $row) {
	$pid = intval($row['pcap_pid'] ?? 0);
	if ($pid > 1 && file_exists("/proc/".$pid)) {
		exec("kill -INT ".escapeshellarg($pid)." 2>/dev/null");
		echo "   stopped running capture (pid $pid)\n";
	}
}
if ($rows) { sleep(2); }	// let tcpdump flush and close

// one object at a time; a missing table just means a partial install
$steps = [
	["DROP TABLE IF EXISTS v_pcap_captures", "schema"],
	["DELETE FROM v_menu_item_groups WHERE menu_item_uuid IN (SELECT menu_item_uuid FROM v_menu_items WHERE menu_item_link LIKE '/app/pcap/%')", "menu groups"],
	["DELETE FROM v_menu_items WHERE menu_item_link LIKE '/app/pcap/%'", "menu item"],
	["DELETE FROM v_group_permissions WHERE permission_name LIKE 'pcap%'", "group permissions"],
	["DELETE FROM v_permissions WHERE permission_name LIKE 'pcap%'", "permissions"],
	["DELETE FROM v_default_settings WHERE default_setting_category = 'pcap'", "default settings"],
];
foreach ($steps as [$sql, $what]) {
	try {
		if ($database->execute($sql) !== false) {
			echo "   removed: $what\n";
		} else {
			echo "   skipped: $what (not present)\n";
		}
	} catch (Throwable $e) {
		echo "   skipped: $what (not present)\n";
	}
}
PHPEOF

# 2. app files
rm -rf "$FUSIONPBX_DIR/app/pcap"
echo "   removed: $FUSIONPBX_DIR/app/pcap"

# 3. tcpdump capabilities
if [ -n "$TCPDUMP" ] && [ -n "$(getcap "$TCPDUMP" 2>/dev/null)" ]; then
	setcap -r "$TCPDUMP"
	echo "   removed: capabilities from $TCPDUMP"
fi

# 4. php-fpm AF_PACKET drop-in (added by install.sh on hardened units)
if command -v systemctl >/dev/null 2>&1; then
	FPM_UNIT="$(systemctl list-units --type=service --state=running --no-legend 2>/dev/null | awk '$1 ~ /^php[0-9.]*-fpm\.service$/ {print $1; exit}')"
	if [ -n "$FPM_UNIT" ] && [ -f "/etc/systemd/system/$FPM_UNIT.d/pcap-afpacket.conf" ]; then
		rm -f "/etc/systemd/system/$FPM_UNIT.d/pcap-afpacket.conf"
		rmdir "/etc/systemd/system/$FPM_UNIT.d" 2>/dev/null || true
		systemctl daemon-reload
		systemctl restart "$FPM_UNIT"
		echo "   removed: AF_PACKET drop-in from $FPM_UNIT (restarted it)"
	fi
fi

# 5. capture files
if [ "$REMOVE_FILES" = 1 ]; then
	rm -rf "$CAPTURE_DIR"
	echo "   removed: capture files in $CAPTURE_DIR"
else
	echo "   kept: capture files in $CAPTURE_DIR (use --remove-files to delete them)"
fi

echo "== done. Log out and back in to refresh the menu."
