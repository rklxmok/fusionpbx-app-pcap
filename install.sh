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

echo "== Packet Capture installer =="

# prerequisites: install anything missing via apt when available,
# otherwise bail with instructions
missing=""
command -v tcpdump >/dev/null || missing="$missing tcpdump"
command -v setcap >/dev/null || missing="$missing libcap2-bin"
if [ -n "$missing" ]; then
	if command -v apt-get >/dev/null; then
		echo "== installing prerequisites:$missing"
		DEBIAN_FRONTEND=noninteractive apt-get update -qq
		DEBIAN_FRONTEND=noninteractive apt-get install -y -qq $missing || {
			echo "apt install failed — install manually:$missing"; exit 1;
		}
	else
		echo "missing prerequisites:$missing — install them, then re-run."; exit 1
	fi
fi
TCPDUMP="$(command -v tcpdump)"

# note: Stop-now signals via the shell 'kill' builtin — no PHP posix
# extension is required (web SAPIs often lack it)

# capabilities instead of sudo: the web user can capture, but nothing else
setcap cap_net_raw,cap_net_admin+ep "$TCPDUMP"
echo "   tcpdump capabilities: $(getcap "$TCPDUMP")"

# php-fpm sandbox: Debian's stock php-fpm units (trixie and later) restrict
# the address families the service's children may open, and AF_PACKET is not
# on the list. tcpdump spawned from a web request then dies instantly with
# "Packet capture is not supported on that device" and every capture flips
# to completed. The CLI verification below can't catch this (no filter in a
# plain shell). A drop-in restating the stock families + AF_PACKET fixes it;
# the line replaces the unit's list, so all stock families must be restated.
if command -v systemctl >/dev/null 2>&1; then
	FPM_UNIT="$(systemctl list-units --type=service --state=running --no-legend 2>/dev/null | awk '$1 ~ /^php[0-9.]*-fpm\.service$/ {print $1; exit}')"
	if [ -n "$FPM_UNIT" ]; then
		RAF="$(systemctl show -p RestrictAddressFamilies --value "$FPM_UNIT" 2>/dev/null || true)"
		case " $RAF " in
			*AF_PACKET*|"  ")	: ;;	# already allowed, or unit not restricted
			*)
				mkdir -p "/etc/systemd/system/$FPM_UNIT.d"
				cat > "/etc/systemd/system/$FPM_UNIT.d/pcap-afpacket.conf" <<CONF
# pcap app: captures spawned from php-fpm need an AF_PACKET socket.
# This line replaces the unit's RestrictAddressFamilies list; the stock
# families are restated with AF_PACKET appended.
[Service]
RestrictAddressFamilies=$RAF AF_PACKET
CONF
				systemctl daemon-reload
				systemctl restart "$FPM_UNIT"
				echo "   $FPM_UNIT: added AF_PACKET to its address-family sandbox and restarted it"
				;;
		esac
	fi
fi

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

# FusionPBX defaults: schema (v_pcap_captures), app defaults, menu, permissions.
# upgrade.php takes one option per run and never prompts; upgrade_menu.php is an interactive menu that loops forever
# on piped input (its readline() has no end-of-file case), so it must not be used from a script.
export TERM="${TERM:-xterm}"
for flag in --schema --defaults --menu --permissions; do
	runuser -u "$WEB_USER" -- php "$FUSIONPBX_DIR/core/upgrade/upgrade.php" "$flag" </dev/null >/dev/null 2>&1 \
		|| echo "   (upgrade.php $flag failed — run Advanced > Upgrade in the UI)"
done

# verify: can the web user actually capture?
if runuser -u "$WEB_USER" -- "$TCPDUMP" -i lo -c 1 -n -w /tmp/pcap-install-test.pcap >/dev/null 2>&1; then
	rm -f /tmp/pcap-install-test.pcap
	echo "   verification: capture as $WEB_USER OK"
else
	echo "   verification FAILED: $WEB_USER could not run tcpdump — check 'getcap $TCPDUMP'"
fi

echo "== done. Menu: Switch > Packet Capture (superadmin only)."
