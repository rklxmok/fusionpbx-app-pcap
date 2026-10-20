<?php
/*
	Packet Capture — authenticated streaming download of a capture file.
	Files live outside the web root; nothing is directly URL-addressable.
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/pdo.php";
	require_once "resources/check_auth.php";

//permission check
	if (permission_exists('pcap_view')) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}

	$database = new database;
	require_once "app/pcap/resources/functions.php";

	$pcap_uuid = $_REQUEST['id'] ?? '';
	$filename = $_REQUEST['file'] ?? '';

//the file must belong to this capture record — no arbitrary paths
	$row = $database->select("SELECT * FROM v_pcap_captures WHERE pcap_uuid = :uuid AND domain_uuid = :domain_uuid", ['uuid' => $pcap_uuid, 'domain_uuid' => $_SESSION['domain_uuid']], 'row');
	if (!$row) { http_response_code(404); exit; }

	$dir = pcap_dir($database, $_SESSION['domain_uuid']);
	$base = $row['pcap_filename'];
	$valid = pcap_files($dir, $base); // [filename => size], basename keys only
	if (!isset($valid[$filename])) { http_response_code(404); exit; }

	$path = $dir."/".$filename;
	if (!file_exists($path)) { http_response_code(404); exit; }

	header("Content-Type: application/vnd.tcpdump.pcap");
	header("Content-Disposition: attachment; filename=\"".$filename."\"");
	header("Content-Length: ".filesize($path));
	header("Cache-Control: no-cache, private");

	// stream in chunks; readfile() would buffer the whole file in memory
	$fh = fopen($path, 'rb');
	while (!feof($fh)) {
		echo fread($fh, 1024 * 1024);
		if (connection_aborted()) { break; }
	}
	fclose($fh);
	exit;

?>
