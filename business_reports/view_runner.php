<?php
/*
	FusionPBX - Business Reports
	View Runner: Execute and display report results
*/

//includes
	require_once "root.php";
	require_once "resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (permission_exists('business_report_view')) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//load classes
	require_once "classes/config.php";
	require_once "classes/view_manager.php";
	require_once "classes/query_builder.php";
	require_once "classes/metric_registry.php";
	require_once "classes/exporter.php";

//get the database object
	$database = new database;

//get view ID
	$view_uuid = isset($_GET['id']) ? $_GET['id'] : null;
	if (!$view_uuid) {
		header("Location: dashboard.php");
		exit;
	}

//load config
	$diagnostics_config = report_config::get_config();

//load view
	$view_manager = new view_manager($database, $_SESSION['domain_uuid'], $_SESSION['user_uuid']);
	$view = $view_manager->get_view($view_uuid);

	if (!$view) {
		message::add('Report not found', 'negative');
		header("Location: dashboard.php");
		exit;
	}

//handle export request
	if (isset($_GET['export']) && $_GET['export'] == 'csv') {
		// Re-execute query instead of trusting POST data
		try {
			$db_type = $database->driver();
			$query_builder = new query_builder($db_type, $diagnostics_config, $_SESSION['domain_uuid']);
			
			$query_plan = $query_builder->build_query($view['definition']);
			
			// Execute query
			$result = $database->execute($query_plan['sql'], $query_plan['params']);
			$results = array();
			
			if ($result) {
				while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
					$results[] = $row;
				}
			}
			
			// Post-process computed metrics
			if (!empty($query_plan['post_process'])) {
				foreach ($results as &$row) {
					foreach ($query_plan['post_process'] as $instruction) {
						$metric_id = $instruction['metric'];
						
						if ($metric_id == 'asr' && isset($row['connected_calls']) && isset($row['total_calls'])) {
							$row['asr'] = $row['total_calls'] > 0 ? ($row['connected_calls'] / $row['total_calls']) * 100 : 0;
						} elseif ($metric_id == 'acd_sec' && isset($row['talk_time_sec']) && isset($row['connected_calls'])) {
							$row['acd_sec'] = $row['connected_calls'] > 0 ? ($row['talk_time_sec'] / $row['connected_calls']) : 0;
						}
					}
				}
			}
			
			// Export
			if ($results) {
				$metric_registry = new metric_registry(
					$db_type,
					$diagnostics_config['field_mapping'],
					$diagnostics_config['counting_unit'],
					$diagnostics_config['call_id_field']
				);
				$exporter = new report_exporter($metric_registry);
				$filename = preg_replace('/[^a-z0-9_-]/i', '_', $view['name']) . '_' . date('Y-m-d_His') . '.csv';
				$exporter->export_csv($results, $view['definition'], $filename);
				exit;
			}
		} catch (Exception $e) {
			// Error during export
		}
	}

//build and execute query
	$results = array();
	$error = null;
	$query_info = null;

	try {
		$db_type = $database->driver();
		$query_builder = new query_builder($db_type, $diagnostics_config, $_SESSION['domain_uuid']);
		
		$query_plan = $query_builder->build_query($view['definition']);
		$query_info = $query_plan;
		
		// Execute query
		$result = $database->execute($query_plan['sql'], $query_plan['params']);
		
		if ($result) {
			while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
				$results[] = $row;
			}
		}
		
		// Post-process computed metrics (ASR, ACD)
		if (!empty($query_plan['post_process'])) {
			foreach ($results as &$row) {
				foreach ($query_plan['post_process'] as $instruction) {
					$metric_id = $instruction['metric'];
					$compute_from = $instruction['compute_from'];
					
					if ($metric_id == 'asr' && isset($row['connected_calls']) && isset($row['total_calls'])) {
						$row['asr'] = $row['total_calls'] > 0 ? ($row['connected_calls'] / $row['total_calls']) * 100 : 0;
					} elseif ($metric_id == 'acd_sec' && isset($row['talk_time_sec']) && isset($row['connected_calls'])) {
						$row['acd_sec'] = $row['connected_calls'] > 0 ? ($row['talk_time_sec'] / $row['connected_calls']) : 0;
					}
				}
			}
		}
		
	} catch (Exception $e) {
		$error = $e->getMessage();
	}

//initialize metric registry for formatting
	$metric_registry = new metric_registry(
		$db_type,
		$diagnostics_config['field_mapping'],
		$diagnostics_config['counting_unit'],
		$diagnostics_config['call_id_field']
	);

//include the header
	require_once "resources/header.php";
	$document['title'] = htmlspecialchars($view['name']);

//show the content
	echo "<div class='action_bar sub'>\n";
	echo "	<div class='heading'><b>" . htmlspecialchars($view['name']) . "</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>'Back','icon'=>$_SESSION['theme']['button_icon_back'],'link'=>'dashboard.php']);
	if (permission_exists('business_report_edit')) {
		echo button::create(['type'=>'button','label'=>'Edit','icon'=>$_SESSION['theme']['button_icon_edit'],'link'=>'view_builder.php?id=' . $view_uuid]);
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

echo "<div style='margin: 20px;'>\n";

// View Info
echo "<div style='background: #f9f9f9; padding: 15px; margin-bottom: 20px; border: 1px solid #ddd;'>\n";
echo "<h3>" . htmlspecialchars($view['name']) . "</h3>\n";
echo "<p>" . htmlspecialchars($view['description']) . "</p>\n";
echo "<p><strong>Call Type:</strong> " . ucfirst($view['definition']['call_type']) . " | ";
echo "<strong>Counting Mode:</strong> " . ucfirst($diagnostics_config['counting_unit']) . "-based | ";
echo "<strong>CDR Source:</strong> " . htmlspecialchars($diagnostics_config['cdr_source']) . "</p>\n";
echo "</div>\n";

// Show error if any
if ($error) {
	echo "<div class='message error'>Error executing report: " . htmlspecialchars($error) . "</div>\n";
}

// Show results
if (count($results) > 0) {
	echo "<p><strong>Results:</strong> " . count($results) . " row(s)</p>\n";
	
	// Export form
	echo "<form method='get' action='view_runner.php' id='export_form'>\n";
	echo "<input type='hidden' name='id' value='" . $view_uuid . "' />\n";
	echo "<input type='hidden' name='export' value='csv' />\n";
	echo button::create(['type'=>'submit','label'=>$text['button-export_csv'],'icon'=>$_SESSION['theme']['button_icon_download']]);
	echo "</form>\n";
	
	echo "<br />\n";
	
	// Results table
	echo "<div style='overflow-x: auto;'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	
	// Headers
	$sample_row = $results[0];
	foreach (array_keys($sample_row) as $col_name) {
		$display_name = $col_name;
		
		// Format column names nicely
		if ($col_name == 'start_date') {
			$display_name = 'Date';
		} elseif ($col_name == 'group_dimension') {
			$group_by = $view['definition']['group_by'];
			$display_name = isset($group_by['dimension']) ? ucfirst($group_by['dimension']) : 'Dimension';
		} else {
			$metric = $metric_registry->get_metric($col_name);
			if ($metric) {
				$display_name = $metric['label'];
			}
		}
		
		echo "<th>" . htmlspecialchars($display_name) . "</th>\n";
	}
	echo "</tr>\n";
	
	// Data rows
	foreach ($results as $row) {
		echo "<tr class='list-row'>\n";
		foreach ($row as $col_name => $value) {
			$formatted_value = $value;
			
			// Format based on metric type
			if (in_array($col_name, $view['definition']['metrics'])) {
				$formatted_value = $metric_registry->format_value($col_name, $value);
			}
			
			echo "<td>" . htmlspecialchars($formatted_value) . "</td>\n";
		}
		echo "</tr>\n";
	}
	
	echo "</table>\n";
	echo "</div>\n";
	
} elseif (!$error) {
	echo "<p>No results found for the selected criteria.</p>\n";
}

// Debug info (for development - can be removed)
if (isset($_GET['debug']) && $_GET['debug'] == '1' && $query_info) {
	echo "<div style='margin-top: 30px; background: #f0f0f0; padding: 15px; border: 1px solid #ccc;'>\n";
	echo "<h4>Debug Information</h4>\n";
	echo "<p><strong>SQL:</strong></p>\n";
	echo "<pre style='background: white; padding: 10px; overflow-x: auto;'>" . htmlspecialchars($query_info['sql']) . "</pre>\n";
	echo "<p><strong>Parameters:</strong></p>\n";
	echo "<pre style='background: white; padding: 10px;'>" . htmlspecialchars(print_r($query_info['params'], true)) . "</pre>\n";
	echo "</div>\n";
}

echo "</div>\n";

//include the footer
	require_once "resources/footer.php";

?>
