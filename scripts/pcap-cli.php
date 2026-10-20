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
	echo "starting 10s capture on $iface as ", get_current_user(), "\n";
	list($ok, $msg) = pcap_start($database, $domain_uuid, $iface, 0, 0, 10, 'sip', 'cli-test');
	echo $ok ? "OK: " : "FAIL: ", $msg, PHP_EOL;
	sleep(12);
	pcap_reconcile($database);
	$rows = $database->select("SELECT * FROM v_pcap_captures ORDER BY insert_date DESC LIMIT 1", null, 'all');
	foreach ($rows as $r) {
		$files = pcap_files(pcap_dir($database, $domain_uuid), $r['pcap_filename']);
		echo "status=", $r['pcap_status'], " files=", count($files), " bytes=", array_sum($files), PHP_EOL;
	}
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
