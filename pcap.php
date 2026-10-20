<?php
/*
	Packet Capture — run, stop, download tcpdump captures from the UI.
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/pdo.php";
	require_once "resources/check_auth.php";
	require_once "resources/functions.php";

//permission check
	if (permission_exists('pcap_view')) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

	$database = new database;
	$domain_uuid = $_SESSION['domain_uuid'];

	require_once "app/pcap/resources/functions.php";

//actions
	$action = $_REQUEST['action'] ?? '';
	if ($action == 'start' && permission_exists('pcap_add')) {
		list($ok, $msg) = pcap_start($database, $domain_uuid,
			$_POST['pcap_interface'] ?? '', $_POST['duration_h'] ?? 0, $_POST['duration_m'] ?? 0, $_POST['duration_s'] ?? 0,
			$_POST['pcap_filter'] ?? 'sip', $_POST['pcap_name'] ?? '');
		message::add($msg, $ok ? 'positive' : 'negative');
		header("Location: pcap.php");
		exit;
	}
	if ($action == 'stop' && permission_exists('pcap_add')) {
		list($ok, $msg) = pcap_stop($database, $_POST['id'] ?? '');
		message::add($msg, $ok ? 'positive' : 'negative');
		header("Location: pcap.php");
		exit;
	}
	if ($action == 'delete' && permission_exists('pcap_delete')) {
		$ok = pcap_delete($database, $domain_uuid, $_POST['id'] ?? '');
		message::add($ok ? "Capture deleted." : "Could not delete (running captures must be stopped first).", $ok ? 'positive' : 'negative');
		header("Location: pcap.php");
		exit;
	}

//refresh statuses against /proc before rendering
	pcap_reconcile($database);
	$has_running = $database->select("SELECT count(*) AS n FROM v_pcap_captures WHERE pcap_status = 'running'", null, 'row');
	if (($has_running['n'] ?? 0) > 0) {
		header("Refresh: 10");
	}

//include the header
	$document['title'] = $text['title-pcap'];
	require_once "resources/header.php";

	$dir = pcap_dir($database, $domain_uuid);
	$interfaces = pcap_interfaces();
	$filters = pcap_filters();
	$captures = $database->select("SELECT * FROM v_pcap_captures ORDER BY insert_date DESC", null, 'all');

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-pcap']."</b></div>\n";
	echo "	<div class='actions'></div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";
	echo $text['description-pcap']."\n";
	echo "<br /><br />\n";

//running capture status + stop
	$running = false;
	foreach ((array) $captures as $c) {
		if ($c['pcap_status'] != 'running') { continue; }
		$running = true;
		$elapsed = time() - strtotime($c['insert_date']);
		$remaining = max(0, intval($c['pcap_duration']) - $elapsed);
		$total = 0;
		foreach (pcap_files($dir, $c['pcap_filename']) as $size) { $total += $size; }
		echo "<div style='background: #eaf4ea; border: 1px solid #b6d4b6; padding: 8px; margin-bottom: 10px;'>\n";
		echo "<b>Running:</b> ".escape($c['pcap_filename']).".pcap &mdash; ".escape($c['pcap_interface'])." &mdash; ".escape($c['pcap_filter'])
			." &mdash; ".number_format($total / 1048576, 1)." MB" ." &mdash; planned ".sprintf('%d:%02d:%02d', intdiv($c['pcap_duration'], 3600), intdiv($c['pcap_duration'], 60) % 60, $c['pcap_duration'] % 60) ." &mdash; stops in ".sprintf('%d:%02d:%02d', intdiv($remaining, 3600), intdiv($remaining, 60) % 60, $remaining % 60)."\n";
		echo "<form method='post' style='display:inline; float: right;'>\n";
		echo "	<input type='hidden' name='action' value='stop'>\n";
		echo "	<input type='hidden' name='id' value='".escape($c['pcap_uuid'])."'>\n";
		echo button::create(['type'=>'submit','label'=>'Stop now','icon'=>'stop']);
		echo "</form>\n";
		echo "</div>\n";
	}

//start form
	if (!$running) {
	echo "<form name='frm' id='frm' method='post'>\n";
	echo "<input type='hidden' name='action' value='start'>\n";
	echo "<table class='form'>\n";
	echo "	<tr>\n";
	echo "		<td class='vncellreq'>".$text['label-interface']."</td>\n";
	echo "		<td class='vtable'><select class='formfld' name='pcap_interface'>\n";
	foreach ($interfaces as $iface) {
		echo "			<option value='".escape($iface)."'>".escape($iface)."</option>\n";
	}
	echo "		</select></td>\n";
	echo "	</tr>\n";
	echo "	<tr>\n";
	echo "		<td class='vncellreq'>".$text['label-duration']."</td>\n";
	echo "		<td class='vtable'>\n";
	echo "			<input type='text' class='formfld' style='width: 40px;' name='duration_h' value='0'> h\n";
	echo "			<input type='text' class='formfld' style='width: 40px;' name='duration_m' value='5'> m\n";
	echo "			<input type='text' class='formfld' style='width: 40px;' name='duration_s' value='0'> s\n";
	echo "		</td>\n";
	echo "	</tr>\n";
	echo "	<tr>\n";
	echo "		<td class='vncellreq'>".$text['label-filter']."</td>\n";
	echo "		<td class='vtable'><select class='formfld' name='pcap_filter'>\n";
	foreach ($filters as $key => $f) {
		echo "			<option value='".escape($key)."'".($key == 'sip' ? " selected='selected'" : '').">".escape($f['label'])."</option>\n";
	}
	echo "		</select></td>\n";
	echo "	</tr>\n";
	echo "	<tr>\n";
	echo "		<td class='vncell'>".$text['label-name']."</td>\n";
	echo "		<td class='vtable'><input type='text' class='formfld' name='pcap_name' placeholder='".gmdate("Y-m-d\THis\Z")."' maxlength='100'><br />".$text['description-name']."</td>\n";
	echo "	</tr>\n";
	echo "</table>\n";
	echo "<div style='margin-top: 6px;'>\n";
	echo button::create(['type'=>'submit','label'=>$text['button-start'],'icon'=>'play']);
	echo "</div>\n";
	echo "</form>\n";
	} // end start-form visibility
	echo "<br /><br />\n";

//capture list
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>".$text['label-name']."</th>\n";
	echo "	<th>".$text['label-interface']."</th>\n";
	echo "	<th>".$text['label-filter']."</th>\n";
	echo "	<th>Files / Size</th>\n";
	echo "	<th>Started</th>\n";
	echo "	<th>Status</th>\n";
	echo "	<th style='width: 60px;'>&nbsp;</th>\n";
	echo "</tr>\n";
	$x = 0;
	if (is_array($captures) && count($captures) > 0) {
		foreach ($captures as $c) {
			$files = pcap_files($dir, $c['pcap_filename']);
			$total = 0; foreach ($files as $size) { $total += $size; }
			echo "<tr class='list-row'>\n";
			echo "	<td>".escape($c['pcap_filename'])."&nbsp;</td>\n";
			echo "	<td>".escape($c['pcap_interface'])."&nbsp;</td>\n";
			echo "	<td>".escape($c['pcap_filter'])."&nbsp;</td>\n";
			echo "	<td>".count($files)." file".(count($files) == 1 ? '' : 's').", ".number_format($total / 1048576, 1)." MB&nbsp;</td>\n";
			echo "	<td class='no-wrap'>".escape($c['insert_date'])."&nbsp;</td>\n";
			echo "	<td>".escape($c['pcap_status'])."&nbsp;</td>\n";
			echo "	<td class='no-button' style='text-align: right;'>\n";
			if (count($files) > 0) {
				echo "<a href='pcap_download.php?id=".escape($c['pcap_uuid'])."&all=1'><b>Download all</b></a><br />\n";
				foreach ($files as $filename => $size) {
					echo "<a href='pcap_download.php?id=".escape($c['pcap_uuid'])."&file=".urlencode($filename)."'>".escape($filename)."</a><br />\n";
				}
			}
			if (permission_exists('pcap_delete') && $c['pcap_status'] != 'running') {
				echo "<form method='post' style='display:inline;'>\n";
				echo "	<input type='hidden' name='action' value='delete'>\n";
				echo "	<input type='hidden' name='id' value='".escape($c['pcap_uuid'])."'>\n";
				echo button::create(['type'=>'submit','label'=>$text['button-delete'],'icon'=>$_SESSION['theme']['button_icon_delete'],'onclick'=>"if (!confirm('".$text['confirm-delete']."')) { this.blur(); return false; }"]);
				echo "</form>\n";
			}
			echo "	</td>\n";
			echo "</tr>\n";
			$x++;
		}
	}
	else {
		echo "<tr class='list-row'><td colspan='7' style='text-align: center;'>No captures yet.</td></tr>\n";
	}
	echo "</table>\n";

//include the footer
	require_once "resources/footer.php";

?>
