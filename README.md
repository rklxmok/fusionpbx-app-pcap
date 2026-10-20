# Packet Capture — FusionPBX app

Run, stop and download **tcpdump packet captures on the PBX itself, from the
FusionPBX UI** — pick the interface, set a duration, hit start, download the
`.pcap` from the browser and open it in Wireshark. Built for SIP
troubleshooting ("prove it in a pcap") without SSH-ing into the box.

```
UI (Switch → Packet Capture, superadmin only)
  ├─ start:  interface dropdown · duration h/m/s (1s–24h) · filter preset
  │          · name (defaults to current UTC time-date, e.g. 2026-10-20T154210Z.pcap)
  ├─ stop:   live size + countdown on running captures, Stop-now button
  └─ files:  every rotated file listed with size, download links, delete
```

The engine runs, unprivileged:

```
timeout -s INT <duration>s tcpdump -i <iface> -n -s0 -C 100 -W 40 \
    -w /var/lib/fusionpbx/pcap/<name>.pcap 'udp or tcp port 5060 or tcp port 5061'
```

## Security design

This app runs a packet-sniffing tool from a web form, so the privilege model
is the whole game:

- **No sudo, no root.** `install.sh` grants tcpdump file capabilities
  (`cap_net_raw,cap_net_admin+ep`), so the web server user captures directly.
  There is no sudoers entry — tcpdump's `-z` postrotate hooks and arbitrary
  `-w` paths make blanket sudo-on-tcpdump effectively root.
- **Fixed filter presets only** (SIP / SIP+RTP / All). Never a free-form BPF
  field — that's command-injection bait. tcpdump receives no user-authored
  filter text, and all command parts are escapeshellarg'd.
- **Interface validated** against the server's real interface list
  (physical NICs + bridges from `/sys/class/net`).
- **Filenames are server-generated and sanitized**; collisions rejected.
- **Captures live outside the web root** (`/var/lib/fusionpbx/pcap`, the
  `pcap::dir` default setting) — nothing is directly URL-addressable.
  Downloads stream through an authenticated page that only serves files
  belonging to a capture row in the database.
- **One capture at a time**, max 24 hours, rotation capped at 100 MB × 40
  files (4 GB) per capture.
- **superadmin only** by default; packet captures contain phone numbers and
  RTP, so `pcap_*` permissions are not granted to the admin group.

## Install

```
sudo ./install.sh
```

Does four things: sets tcpdump's capabilities, creates the capture
directory (owned by the web user, `0750`), copies the app, and runs the
FusionPBX defaults (schema for `v_pcap_captures`, app defaults, menu,
permissions). It verifies at the end that the web user can actually capture —
if that check fails, look at `getcap $(which tcpdump)`.

Prerequisites (`tcpdump`, `libcap2-bin`) are auto-installed via apt when
missing; on non-Debian systems the installer tells you what to install and
exits. Stop-now signals via the shell `kill` builtin, so no PHP `posix`
extension is needed.

## Usage

- **Switch → Packet Capture** in the FusionPBX menu.
- Pick the interface, duration, filter; leave the name empty for the UTC
  timestamp default, or type one (sanitized to `A-Za-z0-9._-`). The capture
  type is always prefixed: `sip-2026-10-20T154210Z.pcap`, `all-...`,
  `sip_rtp-...` — files are self-describing on disk.
- The running banner shows captured size and time remaining, with **Stop
  now**.
- **Download all** streams a single `.tar` of every rotated file for the
  capture; individual rotated files (`name.pcap00`, `name.pcap01`, …) are
  also downloadable one at a time. Delete removes the row and all its files.

## CLI harness

`scripts/pcap-cli.php` exercises the exact same functions the UI uses,
without needing a browser session (run on the PBX):

```
sudo -u www-data php scripts/pcap-cli.php          # list interfaces/filters
sudo -u www-data php scripts/pcap-cli.php start    # 10s test capture
sudo -u www-data php scripts/pcap-cli.php check    # status of recent captures
```

## Storage & settings

| Setting | Meaning |
|---|---|
| `pcap::dir` | Capture directory (default `/var/lib/fusionpbx/pcap`); keep it outside the web root and on a volume with room — SIP on a busy PBX writes roughly 1–10 MB/min |

Table `v_pcap_captures` tracks interface, filter label, filename, duration,
pid and status (running/completed); statuses are reconciled against `/proc`
on every page view, so a crashed or rebooted capture never shows as running
forever.
