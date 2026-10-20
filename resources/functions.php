<?php
// pcap app — shared logic. Deliberately self-contained so it can be
// exercised from a CLI harness (see scripts/pcap-cli.php) as well as the UI.

// Where capture files live: the pcap::dir default setting, created by
// app_defaults/install.sh. Must be writable by the web user and OUTSIDE the
// web root (never expose packet captures via a direct URL).
function pcap_dir($database, $domain_uuid) {
	$row = $database->select("SELECT default_setting_value FROM v_default_settings WHERE default_setting_category = 'pcap' AND default_setting_subcategory = 'dir' AND default_setting_enabled = 'true'", null, 'row');
	$dir = $row['default_setting_value'] ?? '/var/lib/fusionpbx/pcap';
	return $dir;
}

// Filter presets. Fixed strings only — a free-form BPF field from a web form
// is command-injection bait; tcpdump gets no user-authored filter text.
function pcap_filters() {
	return [
		'sip' => [
			'label' => "SIP signaling (5060/5061)",
			'bpf' => "udp or tcp port 5060 or tcp port 5061",
		],
		'sip_rtp' => [
			'label' => "SIP + RTP",
			'bpf' => "(udp or tcp port 5060 or tcp port 5061) or (udp portrange 16384-32768)",
		],
		'all' => [
			'label' => "All traffic",
			'bpf' => "",
		],
	];
}

// Candidate interfaces for the dropdown: physical NICs (have a device
// symlink) plus bridges (have a bridge dir). lo and tunnels are excluded.
function pcap_interfaces() {
	$interfaces = [];
	foreach (glob("/sys/class/net/*") as $path) {
		$name = basename($path);
		if ($name == 'lo') { continue; }
		if (file_exists($path."/device")) {
			$interfaces[] = $name;
		}
		elseif (file_exists($path."/bridge")) {
			$interfaces[] = $name;
		}
	}
	sort($interfaces);
	return $interfaces;
}

// Files belonging to one capture (tcpdump -C/-W rotation: base.pcap,
// base.pcap1, base.pcap2, ...). Returns [filename => size] or [] if none yet.
function pcap_files($dir, $base) {
	$files = [];
	foreach (glob($dir."/".$base.".pcap*") ?: [] as $f) {
		$files[basename($f)] = filesize($f);
	}
	ksort($files, SORT_NATURAL);
	return $files;
}

// Reconcile DB state with reality: a 'running' row whose pid is gone either
// finished (timeout elapsed) or died. Refresh status before every page view.
function pcap_reconcile($database) {
	$rows = $database->select("SELECT pcap_uuid, pcap_pid, pcap_status FROM v_pcap_captures WHERE pcap_status = 'running'", null, 'all');
	if (is_array($rows)) {
		foreach ($rows as $row) {
			$alive = false;
			if (!empty($row['pcap_pid']) && file_exists("/proc/".intval($row['pcap_pid']))) {
				$alive = true;
			}
			if (!$alive) {
				$database->execute("UPDATE v_pcap_captures SET pcap_status = 'completed', update_date = now() WHERE pcap_uuid = :uuid", ['uuid' => $row['pcap_uuid']]);
			}
		}
	}
}

// Start a capture. All inputs are validated against server-side lists; the
// command is assembled from fixed shapes and escapeshellarg'd parts. tcpdump
// must hold cap_net_raw (see install.sh) so this runs unprivileged.
// Returns [ok(bool), message(string)].
function pcap_start($database, $domain_uuid, $interface, $duration_h, $duration_m, $duration_s, $filter_key, $name = '') {

	// one capture at a time
	$running = $database->select("SELECT count(*) AS n FROM v_pcap_captures WHERE pcap_status = 'running'", null, 'row');
	if (($running['n'] ?? 0) > 0) {
		return [false, "A capture is already running — stop it first."];
	}

	// validate interface
	if (!in_array($interface, pcap_interfaces(), true)) {
		return [false, "Invalid interface."];
	}

	// validate duration
	$duration = 3600 * max(0, intval($duration_h)) + 60 * max(0, intval($duration_m)) + max(1, intval($duration_s));
	if ($duration <= 0 || $duration > 86400) {
		return [false, "Duration must be between 1 second and 24 hours."];
	}

	// validate filter
	$filters = pcap_filters();
	if (!isset($filters[$filter_key])) {
		return [false, "Invalid filter."];
	}
	$bpf = $filters[$filter_key]['bpf'];

	// filename: user-provided sanitized, else current UTC time-date; the
	// capture type is always prefixed so files are self-describing
	// (sip-2026-10-20T154210Z.pcap, all-..., sip_rtp-...)
	$base = $name;
	if ($base == '') { $base = gmdate("Y-m-d\THis\Z"); }
	$base = preg_replace('/[^A-Za-z0-9._-]/', '_', $base);
	$base = preg_replace('/\.pcap$/', '', $base);
	if ($base == '' || $base == '.') { return [false, "Invalid capture name."]; }
	$base = $filter_key."-".$base;

	$dir = pcap_dir($database, $domain_uuid);
	if (!is_dir($dir) || !is_writable($dir)) {
		return [false, "Capture directory is not writable: ".$dir];
	}
	if (glob($dir."/".$base.".pcap*")) {
		return [false, "A capture named '".$base."' already exists."];
	}

	// timeout -s INT so tcpdump flushes and closes the pcap cleanly
	// -n no DNS, -s0 full snaplen, -C 100MB x -W 40 rotation (4GB ceiling)
	$cmd = "timeout -s INT ".intval($duration)."s tcpdump"
		." -i ".escapeshellarg($interface)
		." -n -s0"
		." -C 100 -W 40"
		." -w ".escapeshellarg($dir."/".$base.".pcap");
	if ($bpf != '') {
		$cmd .= " ".escapeshellarg($bpf);
	}
	$cmd .= " >/dev/null 2>&1 & echo $!";

	$pid = trim(shell_exec($cmd) ?? '');
	if (!ctype_digit($pid)) {
		return [false, "Failed to start tcpdump (check that it holds cap_net_raw: getcap \$(which tcpdump))."];
	}

	$database->execute("INSERT INTO v_pcap_captures (domain_uuid, pcap_uuid, pcap_interface, pcap_filter, pcap_filename, pcap_duration, pcap_pid, pcap_status, insert_date) VALUES (:domain_uuid, :uuid, :interface, :filter, :filename, :duration, :pid, 'running', now())", [
		'domain_uuid' => $domain_uuid, 'uuid' => uuid(),
		'interface' => $interface, 'filter' => $filters[$filter_key]['label'],
		'filename' => $base, 'duration' => $duration, 'pid' => $pid,
	]);

	return [true, "Capture '".$base."' started on ".$interface." for ".intval($duration)."s."];
}

// Stop a running capture by uuid (SIGINT to `timeout`, which forwards to
// tcpdump — the pcap file is flushed and closed). Unprivileged: the process
// belongs to the web user. Signaled via the shell kill builtin — do NOT use
// posix_kill/SIGINT here: many PHP web SAPI builds lack the posix extension
// and those are fatal undefined-constant errors.
// Returns [ok(bool), message(string)].
function pcap_stop($database, $pcap_uuid) {
	$row = $database->select("SELECT pcap_pid FROM v_pcap_captures WHERE pcap_uuid = :uuid AND pcap_status = 'running'", ['uuid' => $pcap_uuid], 'row');
	if (!$row) {
		return [false, "Capture is not running."];
	}
	$pid = intval($row['pcap_pid']);
	if ($pid <= 1) {
		return [false, "Capture has no recorded pid."];
	}
	if (!file_exists("/proc/".$pid)) {
		$database->execute("UPDATE v_pcap_captures SET pcap_status = 'completed', update_date = now() WHERE pcap_uuid = :uuid", ['uuid' => $pcap_uuid]);
		return [true, "Capture had already finished."];
	}
	exec("kill -INT ".escapeshellarg($pid)." 2>/dev/null", $output, $rc);
	if ($rc == 0) {
		// tcpdump may take a moment to flush; reconcile will confirm later
		return [true, "Stop signal sent."];
	}
	return [false, "Could not stop the capture process (pid ".$pid.")."];
}

// Delete a capture row and its files.
function pcap_delete($database, $domain_uuid, $pcap_uuid) {
	$row = $database->select("SELECT * FROM v_pcap_captures WHERE pcap_uuid = :uuid AND domain_uuid = :domain_uuid", ['uuid' => $pcap_uuid, 'domain_uuid' => $domain_uuid], 'row');
	if (!$row) { return false; }
	if ($row['pcap_status'] == 'running') { return false; }
	$dir = pcap_dir($database, $domain_uuid);
	foreach (glob($dir."/".$row['pcap_filename'].".pcap*") ?: [] as $f) {
		@unlink($f);
	}
	$database->execute("DELETE FROM v_pcap_captures WHERE pcap_uuid = :uuid", ['uuid' => $pcap_uuid]);
	return true;
}

?>
