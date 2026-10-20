<?php

	//application details
		$apps[$x]['name'] = "Packet Capture";
		$apps[$x]['uuid'] = "7e4a1c29-8b3d-4f62-9d1e-6a2b5c4e3f8a";
		$apps[$x]['category'] = "System";
		$apps[$x]['subcategory'] = "";
		$apps[$x]['version'] = "0.1.0";
		$apps[$x]['license'] = "Mozilla Public License 1.1";
		$apps[$x]['url'] = "https://github.com/rklxmok/fusionpbx-app-pcap";
		$apps[$x]['description']['en-us'] = "Run, stop and download packet captures (tcpdump) on this PBX from the UI.";

	//permission details
		$y = 0;
		$apps[$x]['permissions'][$y]['name'] = "pcap_view";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$y += 1;
		$apps[$x]['permissions'][$y]['name'] = "pcap_add";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$y += 1;
		$apps[$x]['permissions'][$y]['name'] = "pcap_delete";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$y += 1;

	//schema details
		$y = 0;
		$z = 0;
		$apps[$x]['db'][$y]['table']['name'] = "v_pcap_captures";
		$apps[$x]['db'][$y]['table']['parent'] = "";

		$apps[$x]['db'][$y]['fields'][$z]['name']['text'] = "domain_uuid";
		$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql'] = "uuid";
		$z++;
		$apps[$x]['db'][$y]['fields'][$z]['name'] = "pcap_uuid";
		$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql'] = "uuid";
		$apps[$x]['db'][$y]['fields'][$z]['key']['type'] = "primary";
		$z++;
		$apps[$x]['db'][$y]['fields'][$z]['name'] = "pcap_interface";
		$apps[$x]['db'][$y]['fields'][$z]['type'] = "text";
		$z++;
		$apps[$x]['db'][$y]['fields'][$z]['name'] = "pcap_filter";
		$apps[$x]['db'][$y]['fields'][$z]['type'] = "text";
		$z++;
		$apps[$x]['db'][$y]['fields'][$z]['name'] = "pcap_filename";
		$apps[$x]['db'][$y]['fields'][$z]['type'] = "text";
		$z++;
		$apps[$x]['db'][$y]['fields'][$z]['name'] = "pcap_duration";
		$apps[$x]['db'][$y]['fields'][$z]['type'] = "numeric";
		$z++;
		$apps[$x]['db'][$y]['fields'][$z]['name'] = "pcap_pid";
		$apps[$x]['db'][$y]['fields'][$z]['type'] = "numeric";
		$z++;
		$apps[$x]['db'][$y]['fields'][$z]['name'] = "pcap_status";
		$apps[$x]['db'][$y]['fields'][$z]['type'] = "text";
		$z++;
		$apps[$x]['db'][$y]['fields'][$z]['name'] = "pcap_error";
		$apps[$x]['db'][$y]['fields'][$z]['type'] = "text";
		$z++;
		$apps[$x]['db'][$y]['fields'][$z]['name']['text'] = "insert_date";
		$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql'] = "timestamptz";
		$z++;
		$apps[$x]['db'][$y]['fields'][$z]['name']['text'] = "update_date";
		$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql'] = "timestamptz";

	//default settings
		$y = 0;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "b8f2d631-4e7c-4a90-8b5d-2c1e9f8a7d3b";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "pcap";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "dir";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "/var/lib/fusionpbx/pcap";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Directory where capture files are stored (must be writable by the web server user, and OUTSIDE the web root)";
		$y++;

?>
