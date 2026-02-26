<?php
/*
	FusionPBX - Business Reports
	Dashboard: List all saved report views
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
	require_once "classes/view_manager.php";

//get the database object
	$database = new database;

//load view manager
	$view_manager = new view_manager($database, $_SESSION['domain_uuid'], $_SESSION['user_uuid']);
	$views = $view_manager->get_accessible_views();

//handle delete action
	if (isset($_POST['action']) && $_POST['action'] == 'delete' && isset($_POST['id'])) {
		if (permission_exists('business_report_delete')) {
			$result = $view_manager->delete_view($_POST['id']);
			if ($result['success']) {
				message::add($text['message-report_deleted']);
			} else {
				message::add($result['error'], 'negative');
			}
			header("Location: dashboard.php");
			exit;
		}
	}

//include the header
	require_once "resources/header.php";
	$document['title'] = $text['title-business_reports'];

//show the content
	echo "<div class='action_bar sub'>\n";
	echo "	<div class='heading'><b>" . $text['title-business_reports'] . "</b></div>\n";
	echo "	<div class='actions'>\n";
	if (permission_exists('business_report_add')) {
		echo button::create(['type'=>'button','label'=>$text['button-new_report'],'icon'=>$_SESSION['theme']['button_icon_add'],'link'=>'view_builder.php']);
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

//show the list of views
	if (count($views) > 0) {
		echo "<table class='list'>\n";
		echo "<tr class='list-header'>\n";
		echo "	<th>" . $text['label-report_name'] . "</th>\n";
		echo "	<th>" . $text['label-report_description'] . "</th>\n";
		echo "	<th class='hide-md-dn'>Call Type</th>\n";
		echo "	<th class='hide-md-dn'>Updated</th>\n";
		echo "	<th class='right' style='width: 100px;'>Actions</th>\n";
		echo "</tr>\n";
		
		foreach ($views as $view) {
			$list_row_url = "view_runner.php?id=" . $view['uuid'];
			
			echo "<tr class='list-row' href='" . $list_row_url . "'>\n";
			echo "	<td><a href='" . $list_row_url . "'>" . htmlspecialchars($view['name']) . "</a></td>\n";
			echo "	<td>" . htmlspecialchars($view['description']) . "</td>\n";
			echo "	<td class='hide-md-dn'>" . ucfirst($view['definition']['call_type']) . "</td>\n";
			echo "	<td class='hide-md-dn'>" . htmlspecialchars($view['updated_at']) . "</td>\n";
			echo "	<td class='right'>\n";
			
			if (permission_exists('business_report_edit')) {
				echo button::create(['type'=>'button','title'=>'Edit','icon'=>$_SESSION['theme']['button_icon_edit'],'link'=>'view_builder.php?id=' . $view['uuid']]);
			}
			if (permission_exists('business_report_delete') && $view['created_by'] == $_SESSION['user_uuid']) {
				echo "<form method='post' action='dashboard.php' style='display:inline;'>";
				echo "<input type='hidden' name='action' value='delete' />";
				echo "<input type='hidden' name='id' value='" . $view['uuid'] . "' />";
				echo button::create(['type'=>'submit','title'=>'Delete','icon'=>$_SESSION['theme']['button_icon_delete'],'onclick'=>"return confirm('Are you sure?')"]);
				echo "</form>";
			}
			
			echo "	</td>\n";
			echo "</tr>\n";
		}
		
		echo "</table>\n";
	} else {
		echo "<p>No saved reports found. Create a new report to get started.</p>\n";
	}

//include the footer
	require_once "resources/footer.php";

?>
