<?php
/*
	FusionPBX - Business Reports
	View Builder: Create and edit report views
*/

//includes
	require_once "root.php";
	require_once "resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	$view_uuid = isset($_GET['id']) ? $_GET['id'] : null;
	if ($view_uuid) {
		if (!permission_exists('business_report_edit')) {
			echo "access denied";
			exit;
		}
	} else {
		if (!permission_exists('business_report_add')) {
			echo "access denied";
			exit;
		}
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//load classes
	require_once "classes/config.php";
	require_once "classes/view_manager.php";
	require_once "classes/metric_registry.php";

//get the database object
	$database = new database;

//load config
	$diagnostics_config = report_config::get_config();

//initialize metric registry
	$db_type = $database->driver();
	$metric_registry = new metric_registry(
		$db_type,
		$diagnostics_config['field_mapping'],
		$diagnostics_config['counting_unit'],
		$diagnostics_config['call_id_field']
	);

//load view if editing
	$view = null;
	if ($view_uuid) {
		$view_manager = new view_manager($database, $_SESSION['domain_uuid'], $_SESSION['user_uuid']);
		$view = $view_manager->get_view($view_uuid);
		
		if (!$view) {
			message::add('Report not found', 'negative');
			header("Location: dashboard.php");
			exit;
		}
	}

//handle form submission
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'save') {
		$name = $_POST['name'];
		$description = $_POST['description'];
		$is_public = isset($_POST['is_public']) ? true : false;
		
		// Build definition
		$definition = array(
			'version' => 1,
			'cdr_source' => $diagnostics_config['cdr_source'],
			'call_type' => $_POST['call_type'],
			'date_range' => array(
				'mode' => 'relative',
				'last_days' => (int)$_POST['last_days']
			),
			'filters' => array(),
			'group_by' => array(
				'time_bucket' => $_POST['time_bucket'],
				'dimension' => $_POST['dimension']
			),
			'metrics' => isset($_POST['metrics']) ? $_POST['metrics'] : array(),
			'sort' => array(
				'by' => $_POST['sort_by'],
				'dir' => $_POST['sort_dir']
			),
			'limit' => (int)$_POST['limit'],
			'display' => array(
				'format_time' => 'hms',
				'chart' => 'none'
			)
		);
		
		$view_manager = new view_manager($database, $_SESSION['domain_uuid'], $_SESSION['user_uuid']);
		
		if ($view_uuid) {
			$result = $view_manager->update_view($view_uuid, $name, $description, $definition, $is_public);
		} else {
			$result = $view_manager->create_view($name, $description, $definition, $is_public);
			if ($result['success']) {
				$view_uuid = $result['uuid'];
			}
		}
		
		if ($result['success']) {
			message::add($text['message-report_saved'], 'positive');
			header("Location: view_runner.php?id=" . $view_uuid);
			exit;
		} else {
			message::add($result['error'], 'negative');
		}
	}

//set default values
	$name = $view ? $view['name'] : '';
	$description = $view ? $view['description'] : '';
	$is_public = $view ? $view['is_public'] : false;
	$definition = $view ? $view['definition'] : array(
		'call_type' => 'any',
		'date_range' => array('mode' => 'relative', 'last_days' => 7),
		'group_by' => array('time_bucket' => 'day', 'dimension' => 'none'),
		'metrics' => array('total_calls', 'connected_calls', 'not_connected_calls', 'talk_time_sec'),
		'sort' => array('by' => 'start_date', 'dir' => 'desc'),
		'limit' => 1000
	);

//include the header
	require_once "resources/header.php";
	$document['title'] = $view ? 'Edit Report' : 'New Report';

//show the content
	echo "<div class='action_bar sub'>\n";
	echo "	<div class='heading'><b>" . ($view ? 'Edit Report' : 'New Report') . "</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>'Cancel','icon'=>$_SESSION['theme']['button_icon_back'],'link'=>'dashboard.php']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

echo "<div style='margin: 20px;'>\n";

echo "<form method='post' action='view_builder.php" . ($view_uuid ? "?id=" . htmlspecialchars($view_uuid) : "") . "'>\n";
echo "<input type='hidden' name='action' value='save' />\n";

echo "<table class='tr_hover' width='100%' cellpadding='0' cellspacing='0' border='0'>\n";

// Report Name
echo "<tr>\n";
echo "<td class='vncellreq' style='width: 30%;'>Report Name:</td>\n";
echo "<td class='vtable'>\n";
echo "<input type='text' name='name' value='" . htmlspecialchars($name) . "' required style='width: 100%; max-width: 500px;' />\n";
echo "</td>\n";
echo "</tr>\n";

// Description
echo "<tr>\n";
echo "<td class='vncell'>Description:</td>\n";
echo "<td class='vtable'>\n";
echo "<textarea name='description' rows='3' style='width: 100%; max-width: 500px;'>" . htmlspecialchars($description) . "</textarea>\n";
echo "</td>\n";
echo "</tr>\n";

// Call Type
echo "<tr>\n";
echo "<td class='vncellreq'>Call Type:</td>\n";
echo "<td class='vtable'>\n";
echo "<select name='call_type' required>\n";
$call_types = array('any' => 'All Calls', 'inbound' => 'Inbound', 'outbound' => 'Outbound', 'local' => 'Local (Internal)');
foreach ($call_types as $value => $label) {
	$selected = ($definition['call_type'] == $value) ? ' selected' : '';
	echo "<option value='" . $value . "'" . $selected . ">" . $label . "</option>\n";
}
echo "</select>\n";
echo "</td>\n";
echo "</tr>\n";

// Date Range
echo "<tr>\n";
echo "<td class='vncellreq'>Date Range (Last N Days):</td>\n";
echo "<td class='vtable'>\n";
$last_days = isset($definition['date_range']['last_days']) ? $definition['date_range']['last_days'] : 7;
echo "<input type='number' name='last_days' value='" . $last_days . "' min='1' max='90' required style='width: 100px;' />\n";
echo "<span class='vexpl'>Maximum 90 days</span>\n";
echo "</td>\n";
echo "</tr>\n";

// Time Bucket
echo "<tr>\n";
echo "<td class='vncellreq'>Group By Time:</td>\n";
echo "<td class='vtable'>\n";
echo "<select name='time_bucket' required>\n";
$time_buckets = array('none' => 'No Grouping', 'day' => 'By Day', 'hour' => 'By Hour');
$current_bucket = isset($definition['group_by']['time_bucket']) ? $definition['group_by']['time_bucket'] : 'day';
foreach ($time_buckets as $value => $label) {
	$selected = ($current_bucket == $value) ? ' selected' : '';
	echo "<option value='" . $value . "'" . $selected . ">" . $label . "</option>\n";
}
echo "</select>\n";
echo "</td>\n";
echo "</tr>\n";

// Dimension
echo "<tr>\n";
echo "<td class='vncell'>Group By Dimension:</td>\n";
echo "<td class='vtable'>\n";
echo "<select name='dimension'>\n";
$dimensions = array('none' => 'No Dimension', 'extension' => 'Extension', 'did' => 'DID', 'gateway' => 'Gateway', 'hangup_cause' => 'Hangup Cause');
$current_dimension = isset($definition['group_by']['dimension']) ? $definition['group_by']['dimension'] : 'none';
foreach ($dimensions as $value => $label) {
	$selected = ($current_dimension == $value) ? ' selected' : '';
	echo "<option value='" . $value . "'" . $selected . ">" . $label . "</option>\n";
}
echo "</select>\n";
echo "</td>\n";
echo "</tr>\n";

// Metrics
echo "<tr>\n";
echo "<td class='vncellreq'>Metrics:</td>\n";
echo "<td class='vtable'>\n";
$all_metrics = $metric_registry->get_all_metrics();
$selected_metrics = isset($definition['metrics']) ? $definition['metrics'] : array();

foreach ($all_metrics as $metric_id => $metric) {
	if ($metric_registry->is_metric_available($metric_id)) {
		$checked = in_array($metric_id, $selected_metrics) ? ' checked' : '';
		echo "<label style='display: block; margin: 5px 0;'>\n";
		echo "<input type='checkbox' name='metrics[]' value='" . $metric_id . "'" . $checked . " /> ";
		echo $metric['label'];
		if (isset($metric['description'])) {
			echo " <span style='color: #666; font-size: 0.9em;'>(" . $metric['description'] . ")</span>";
		}
		echo "</label>\n";
	}
}
echo "</td>\n";
echo "</tr>\n";

// Sort By
echo "<tr>\n";
echo "<td class='vncell'>Sort By:</td>\n";
echo "<td class='vtable'>\n";
$current_sort_by = isset($definition['sort']['by']) ? $definition['sort']['by'] : 'start_date';
echo "<input type='text' name='sort_by' value='" . htmlspecialchars($current_sort_by) . "' style='width: 200px;' />\n";
$current_sort_dir = isset($definition['sort']['dir']) ? $definition['sort']['dir'] : 'desc';
echo "<select name='sort_dir'>\n";
echo "<option value='asc'" . ($current_sort_dir == 'asc' ? ' selected' : '') . ">Ascending</option>\n";
echo "<option value='desc'" . ($current_sort_dir == 'desc' ? ' selected' : '') . ">Descending</option>\n";
echo "</select>\n";
echo "</td>\n";
echo "</tr>\n";

// Limit
echo "<tr>\n";
echo "<td class='vncell'>Result Limit:</td>\n";
echo "<td class='vtable'>\n";
$current_limit = isset($definition['limit']) ? $definition['limit'] : 1000;
echo "<input type='number' name='limit' value='" . $current_limit . "' min='1' max='10000' style='width: 100px;' />\n";
echo "<span class='vexpl'>Maximum 10,000 rows</span>\n";
echo "</td>\n";
echo "</tr>\n";

// Is Public
echo "<tr>\n";
echo "<td class='vncell'>Public Report:</td>\n";
echo "<td class='vtable'>\n";
$public_checked = $is_public ? ' checked' : '';
echo "<input type='checkbox' name='is_public' value='1'" . $public_checked . " />\n";
echo "<span class='vexpl'>Make this report visible to all users</span>\n";
echo "</td>\n";
echo "</tr>\n";

// Submit
echo "<tr>\n";
echo "<td>&nbsp;</td>\n";
echo "<td>\n";
echo "<input type='submit' value='" . ($view ? 'Update Report' : 'Create Report') . "' class='btn' />\n";
echo "</td>\n";
echo "</tr>\n";

echo "</table>\n";
echo "</form>\n";

echo "</div>\n";

//include the footer
	require_once "resources/footer.php";

?>
