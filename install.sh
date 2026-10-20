#!/bin/sh
# Packet Capture — FusionPBX app installer.
#
# Installs the app + FusionPBX defaults, and grants tcpdump the file
# capabilities the capture engine needs (cap_net_raw + cap_net_admin) so the
# web server user can run captures WITHOUT sudo and WITHOUT root. Also
# creates the capture directory outside the web root.
#
# Usage: sudo ./install.sh
set -eu

FUSIONPBX_DIR="${FUSIONPBX_DIR:-/var/www/fusionpbx}"
WEB_USER="${WEB_USER:-www-data}"
CAPTURE_DIR="${CAPTURE_DIR:-/var/lib/fusionpbx/pcap}"
TCPDUMP="$(command -v tcpdump || true)"
APP_DIR="$(cd "$(dirname "$0")" && pwd)"

if [ "$(id -u)" != 0 ]; then
	echo "run as root (sudo ./install.sh)"; exit 1
fi
if [ ! -d "$FUSIONPBX_DIR" ]; then
	echo "FusionPBX not found at $FUSIONPBX_DIR (set FUSIONPBX_DIR=...)"; exit 1
fi
if [ -z "$TCPDUMP" ]; then
	echo "tcpdump not found — install it first: apt-get install tcpdump"; exit 1
fi
if ! command -v setcap >/dev/null; then
	echo "setcap not found — install libcap2-bin: apt-get install libcap2-bin"; exit 1
fi

echo "== Packet Capture installer =="

# capabilities instead of sudo: the web user can capture, but nothing else
setcap cap_net_raw,cap_net_admin+ep "$TCPDUMP"
echo "   tcpdump capabilities: $(getcap "$TCPDUMP")"

# capture directory: outside the web root, owned by the web user
mkdir -p "$CAPTURE_DIR"
chown "$WEB_USER:$WEB_USER" "$CAPTURE_DIR"
chmod 750 "$CAPTURE_DIR"

# app files
mkdir -p "$FUSIONPBX_DIR/app/pcap"
cp -r "$APP_DIR/app_config.php" "$APP_DIR/app_defaults.php" "$APP_DIR/app_languages.php" \
      "$APP_DIR/app_menu.php" "$APP_DIR/root.php" "$APP_DIR"/pcap*.php "$APP_DIR/resources" \
      "$FUSIONPBX_DIR/app/pcap/"
chown -R "$WEB_USER:$WEB_USER" "$FUSIONPBX_DIR/app/pcap"

# FusionPBX defaults: schema (v_pcap_captures), app, menu, permissions
export TERM="${TERM:-xterm}"
runuser -u "$WEB_USER" -- php "$FUSIONPBX_DIR/core/upgrade/upgrade_schema.php" >/dev/null 2>&1 \
	|| echo "   (schema upgrade failed — run Advanced > Upgrade > Schema in the UI)"
for step in 3 4 5; do
	printf "$step\n0\n" | runuser -u "$WEB_USER" -- php "$FUSIONPBX_DIR/core/upgrade/upgrade_menu.php" >/dev/null 2>&1 \
		|| { echo "   (defaults step $step failed — run Advanced > Upgrade in the UI)"; }
done

# verify: can the web user actually capture?
if runuser -u "$WEB_USER" -- "$TCPDUMP" -i lo -c 1 -n -w /tmp/pcap-install-test.pcap >/dev/null 2>&1; then
	rm -f /tmp/pcap-install-test.pcap
	echo "   verification: capture as $WEB_USER OK"
else
	echo "   verification FAILED: $WEB_USER could not run tcpdump — check 'getcap $TCPDUMP'"
fi

echo "== done. Menu: Switch > Packet Capture (superadmin only)."
