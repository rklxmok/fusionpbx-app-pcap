<?php
// CLI harness for the Packet Capture app — exercises the same functions.php
// the UI uses, without needing a browser session. Run as root on the PBX:
//
//   sudo -u www-data php scripts/pcap-cli.php          # list interfaces
//   sudo -u www-data php scripts/pcap-cli.php start     # 10s test capture
//   sudo -u www-data php scripts/pcap-cli.php check    # status + files
//
$_SERVER["DOCUMENT_ROOT"] = "/var/www/fusionpbx";
$_SERVER["PROJECT_ROOT"] = "/var/www/fusionpbx";
$_SERVER["SCRIPT_FILENAME"] = "/var/www/fusionpbx/app/pcap/pcap.php";
require_once "/var/www/fusionpbx/resources/require.php";
$_SESSION["domain_uuid"] = "f36f177b-3e3e-4dca-a260-49c85a44c4ee";
require_once "/var/www/fusionpbx/app/pcap/resources/functions.php";

$database = new database;
$domain_uuid = $_SESSION["domain_uuid"];
$mode = $argv[1] ?? 'list';

if ($mode == 'start') {
	$ifaces = pcap_interfaces();
	if (!$ifaces) { echo "no interfaces found\n"; exit(1); }
	$iface = $ifaces[0];
	$dur = intval($argv[2] ?? 10);
	echo "starting {$dur}s capture on $iface as ", get_current_user(), "\n";
	list($ok, $msg) = pcap_start($database, $domain_uuid, $iface, 0, 0, $dur, 'sip', 'cli-test-'.gmdate('His'));
	echo $ok ? "OK: " : "FAIL: ", $msg, PHP_EOL;
	if ($dur > 15) { echo "(running in background — stop it with: pcap-cli.php stop)\n"; exit(0); }
	sleep(12);
	pcap_reconcile($database);
	$rows = $database->select("SELECT * FROM v_pcap_captures ORDER BY insert_date DESC LIMIT 1", null, 'all');
	foreach ($rows as $r) {
		$files = pcap_files(pcap_dir($database, $domain_uuid), $r['pcap_filename']);
		echo "status=", $r['pcap_status'], " files=", count($files), " bytes=", array_sum($files), PHP_EOL;
	}
}
elseif ($mode == 'stop') {
	// stop the newest running capture — exercises the exact pcap_stop() the
	// UI's Stop-now button calls
	$row = $database->select("SELECT pcap_uuid, pcap_filename FROM v_pcap_captures WHERE pcap_status = 'running' ORDER BY insert_date DESC LIMIT 1", null, 'row');
	if (!$row) { echo "nothing running\n"; exit(0); }
	list($ok, $msg) = pcap_stop($database, $row['pcap_uuid']);
	echo ($ok ? "OK: " : "FAIL: "), $msg, PHP_EOL;
	sleep(2);
	pcap_reconcile($database);
	$row2 = $database->select("SELECT pcap_status FROM v_pcap_captures WHERE pcap_uuid = :uuid", ['uuid' => $row['pcap_uuid']], 'row');
	echo "status after stop: ", $row2['pcap_status'], PHP_EOL;
}
elseif ($mode == 'check') {
	pcap_reconcile($database);
	$rows = $database->select("SELECT * FROM v_pcap_captures ORDER BY insert_date DESC LIMIT 5", null, 'all');
	foreach ($rows as $r) {
		echo $r['pcap_filename'], " ", $r['pcap_status'], " iface=", $r['pcap_interface'], PHP_EOL;
	}
}
else {
	echo "interfaces: ", implode(", ", pcap_interfaces()), PHP_EOL;
	echo "capture dir: ", pcap_dir($database, $domain_uuid), PHP_EOL;
	foreach (pcap_filters() as $key => $f) {
		echo "filter [$key]: ", $f['label'], " => ", $f['bpf'] ?: "(all traffic)", PHP_EOL;
	}
}
