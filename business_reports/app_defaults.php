<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Copyright (C) 2024
	All Rights Reserved.
*/

//check the permission
	if(defined('STDIN')) {
		$document_root = str_replace("\\", "/", $_SERVER["SCRIPT_FILENAME"]);
		$document_root = dirname(dirname(dirname($document_root)));
		$_SERVER["DOCUMENT_ROOT"] = $document_root;
		define('PROJECT_PATH', $document_root);
		set_include_path($document_root);
	}
	
//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (permission_exists('business_report_add') || if_group("superadmin")) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}

//get the database object
	$database = database::new();

//create default report views
	$sql = "SELECT COUNT(*) as count FROM v_report_views";
	$result = $database->execute($sql);
	if ($result && $row = $result->fetch(PDO::FETCH_ASSOC)) {
		if ($row['count'] == 0) {
			//insert default views
			$views = array(
				array(
					'name' => 'Inbound Overview',
					'description' => 'Overview of inbound calls',
					'definition' => array(
						'version' => 1,
						'call_type' => 'inbound',
						'date_range' => array('mode' => 'relative', 'last_days' => 7),
						'filters' => array(),
						'group_by' => array('time_bucket' => 'day', 'dimension' => 'none'),
						'metrics' => array('total_calls', 'connected_calls', 'not_connected_calls', 'talk_time_sec', 'asr', 'acd_sec'),
						'sort' => array('by' => 'start_date', 'dir' => 'desc'),
						'limit' => 1000,
						'display' => array('format_time' => 'hms', 'chart' => 'none')
					)
				),
				array(
					'name' => 'Outbound Overview',
					'description' => 'Overview of outbound calls',
					'definition' => array(
						'version' => 1,
						'call_type' => 'outbound',
						'date_range' => array('mode' => 'relative', 'last_days' => 7),
						'filters' => array(),
						'group_by' => array('time_bucket' => 'day', 'dimension' => 'none'),
						'metrics' => array('total_calls', 'connected_calls', 'not_connected_calls', 'talk_time_sec', 'asr', 'acd_sec'),
						'sort' => array('by' => 'start_date', 'dir' => 'desc'),
						'limit' => 1000,
						'display' => array('format_time' => 'hms', 'chart' => 'none')
					)
				),
				array(
					'name' => 'Local (Internal) Overview',
					'description' => 'Overview of internal calls',
					'definition' => array(
						'version' => 1,
						'call_type' => 'local',
						'date_range' => array('mode' => 'relative', 'last_days' => 7),
						'filters' => array(),
						'group_by' => array('time_bucket' => 'day', 'dimension' => 'none'),
						'metrics' => array('total_calls', 'connected_calls', 'not_connected_calls', 'talk_time_sec'),
						'sort' => array('by' => 'start_date', 'dir' => 'desc'),
						'limit' => 1000,
						'display' => array('format_time' => 'hms', 'chart' => 'none')
					)
				)
			);

			foreach ($views as $view) {
				$report_view_uuid = uuid();
				$sql = "INSERT INTO v_report_views (report_view_uuid, domain_uuid, name, description, definition_json, is_public, created_at, updated_at) ";
				$sql .= "VALUES (:report_view_uuid, NULL, :name, :description, :definition_json, true, NOW(), NOW())";
				
				$parameters = array();
				$parameters['report_view_uuid'] = $report_view_uuid;
				$parameters['name'] = $view['name'];
				$parameters['description'] = $view['description'];
				$parameters['definition_json'] = json_encode($view['definition']);
				
				$database->execute($sql, $parameters);
			}
			
			echo "Default report views created successfully.\n";
		}
	}

?>
