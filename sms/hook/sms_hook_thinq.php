<?php

//includes files
require_once dirname(__DIR__, 3) . "/resources/require.php";
require_once "../sms_hook_common.php";

if (check_acl()) {
	if  ($_SERVER['REQUEST_METHOD'] == 'POST') {
		if ($debug) {
			error_log('[SMS] REQUEST: ' .  print_r($_REQUEST, true));
		}
		route_and_send_sms($_REQUEST['from'], str_replace("+","",$_REQUEST['to']), $_REQUEST['message']);
	} else {
	  die("no");
	}
} else {
	error_log('ACCESS DENIED [SMS]: ' .  print_r($_SERVER['REMOTE_ADDR'], true));
	die("access denied");
}

?>
