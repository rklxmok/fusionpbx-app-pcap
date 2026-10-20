<?php
/*
	Packet Capture — authenticated download of a capture file (or a tar of
	all its rotated files). Files live outside the web root; nothing is
	directly URL-addressable.
*/

// stream a file to the client in chunks (readfile() would buffer it all)
function stream_file($path, $download_name) {
	header("Content-Type: application/vnd.tcpdump.pcap");
	header("Content-Disposition: attachment; filename=\"".$download_name."\"");
	header("Content-Length: ".filesize($path));
	header("Cache-Control: no-cache, private");
	$fh = fopen($path, 'rb');
	while (!feof($fh)) {
		echo fread($fh, 1024 * 1024);
		if (connection_aborted()) { break; }
	}
	fclose($fh);
}

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

//the file(s) must belong to this capture record — no arbitrary paths
	$row = $database->select("SELECT * FROM v_pcap_captures WHERE pcap_uuid = :uuid AND domain_uuid = :domain_uuid", ['uuid' => $pcap_uuid, 'domain_uuid' => $_SESSION['domain_uuid']], 'row');
	if (!$row) { http_response_code(404); exit; }

	$dir = pcap_dir($database, $_SESSION['domain_uuid']);
	$base = $row['pcap_filename'];
	$valid = pcap_files($dir, $base); // [filename => size], basename keys only

//Download-all: stream a tar of every rotated file for this capture
	if (isset($_REQUEST['all'])) {
		$files = array_keys($valid);
		if (count($files) == 0) { http_response_code(404); exit; }
		if (count($files) == 1) {
			stream_file($dir."/".$files[0], $files[0]);
			exit;
		}
		header("Content-Type: application/x-tar");
		header("Content-Disposition: attachment; filename=\"".$base.".tar\"");
		header("Cache-Control: no-cache, private");
		$cmd = "tar -cf - -C ".escapeshellarg($dir)
			. " ".implode(" ", array_map('escapeshellarg', $files));
		passthru($cmd);
		exit;
	}

	if (!isset($valid[$filename])) { http_response_code(404); exit; }

	$path = $dir."/".$filename;
	if (!file_exists($path)) { http_response_code(404); exit; }

	stream_file($path, $filename);
	exit;

	header("Content-Type: application/vnd.tcpdump.pcap");
	header("Content-Disposition: attachment; filename=\"".$filename."\"");
	header("Content-Length: ".filesize($path));
	header("Cache-Control: no-cache, private");



?>
