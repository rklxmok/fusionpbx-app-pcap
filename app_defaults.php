<?php

	//process this only one time
	if ($domains_processed == 1) {

		//ensure the capture directory exists (root/CLI install creates it
		//properly via install.sh; this is best-effort for UI installs)
		$cache = null;
		if (isset($_SESSION['switch']['cache']['dir'])) { $cache = $_SESSION['switch']['cache']['dir']; }
		$dir = $_SESSION['pcap']['dir']['text'] ?? '/var/lib/fusionpbx/pcap';
		if (!is_dir($dir)) {
			@mkdir($dir, 0750, true);
		}

	}

?>
