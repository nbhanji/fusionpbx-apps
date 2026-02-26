<?php
/*
	FusionPBX - Business Reports
	View Manager: CRUD operations for saved views
*/

class view_manager {
	
	private $database;
	private $domain_uuid;
	private $user_uuid;
	
	public function __construct($database, $domain_uuid, $user_uuid = null) {
		$this->database = $database;
		$this->domain_uuid = $domain_uuid;
		$this->user_uuid = $user_uuid;
	}
	
	/**
	 * Get all views accessible by the current user
	 */
	public function get_accessible_views() {
		$views = array();
		
		try {
			// Get public views or views owned by user
			$sql = "SELECT * FROM v_report_views WHERE ";
			$sql .= "(domain_uuid = :domain_uuid OR domain_uuid IS NULL) ";
			$sql .= "AND (is_public = true";
			
			$params = array('domain_uuid' => $this->domain_uuid);
			
			if ($this->user_uuid) {
				$sql .= " OR created_by = :user_uuid";
				$params['user_uuid'] = $this->user_uuid;
			}
			
			$sql .= ") ORDER BY created_at DESC";
			
			$result = $this->database->execute($sql, $params);
			
			if ($result) {
				while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
					$views[] = $this->format_view_record($row);
				}
			}
		} catch (Exception $e) {
			// Error loading views
		}
		
		return $views;
	}
	
	/**
	 * Get a specific view by UUID
	 */
	public function get_view($view_uuid) {
		try {
			$sql = "SELECT * FROM v_report_views WHERE report_view_uuid = :uuid";
			$params = array('uuid' => $view_uuid);
			$result = $this->database->execute($sql, $params);
			
			if ($result && $row = $result->fetch(PDO::FETCH_ASSOC)) {
				// Check access
				if ($this->can_view($row)) {
					return $this->format_view_record($row);
				}
			}
		} catch (Exception $e) {
			// Error loading view
		}
		
		return null;
	}
	
	/**
	 * Create a new view
	 */
	public function create_view($name, $description, $definition, $is_public = false) {
		try {
			// Validate definition
			if (!$this->validate_definition($definition)) {
				return array('success' => false, 'error' => 'Invalid view definition');
			}
			
			$view_uuid = uuid();
			$sql = "INSERT INTO v_report_views (report_view_uuid, domain_uuid, name, description, definition_json, ";
			$sql .= "is_public, created_by, created_at, updated_at) ";
			$sql .= "VALUES (:uuid, :domain_uuid, :name, :description, :definition, :is_public, :created_by, NOW(), NOW())";
			
			$params = array(
				'uuid' => $view_uuid,
				'domain_uuid' => $this->domain_uuid,
				'name' => $name,
				'description' => $description,
				'definition' => json_encode($definition),
				'is_public' => $is_public ? 'true' : 'false',
				'created_by' => $this->user_uuid
			);
			
			$this->database->execute($sql, $params);
			
			return array('success' => true, 'uuid' => $view_uuid);
		} catch (Exception $e) {
			return array('success' => false, 'error' => $e->getMessage());
		}
	}
	
	/**
	 * Update an existing view
	 */
	public function update_view($view_uuid, $name, $description, $definition, $is_public = false) {
		try {
			// Check if user can edit
			$view = $this->get_view($view_uuid);
			if (!$view) {
				return array('success' => false, 'error' => 'View not found');
			}
			
			if (!$this->can_edit($view)) {
				return array('success' => false, 'error' => 'Permission denied');
			}
			
			// Validate definition
			if (!$this->validate_definition($definition)) {
				return array('success' => false, 'error' => 'Invalid view definition');
			}
			
			$sql = "UPDATE v_report_views SET name = :name, description = :description, ";
			$sql .= "definition_json = :definition, is_public = :is_public, updated_at = NOW() ";
			$sql .= "WHERE report_view_uuid = :uuid";
			
			$params = array(
				'uuid' => $view_uuid,
				'name' => $name,
				'description' => $description,
				'definition' => json_encode($definition),
				'is_public' => $is_public ? 'true' : 'false'
			);
			
			$this->database->execute($sql, $params);
			
			return array('success' => true);
		} catch (Exception $e) {
			return array('success' => false, 'error' => $e->getMessage());
		}
	}
	
	/**
	 * Delete a view
	 */
	public function delete_view($view_uuid) {
		try {
			// Check if user can delete
			$view = $this->get_view($view_uuid);
			if (!$view) {
				return array('success' => false, 'error' => 'View not found');
			}
			
			if (!$this->can_delete($view)) {
				return array('success' => false, 'error' => 'Permission denied');
			}
			
			// Delete ACL entries first
			$sql = "DELETE FROM v_report_view_acl WHERE report_view_uuid = :uuid";
			$params = array('uuid' => $view_uuid);
			$this->database->execute($sql, $params);
			
			// Delete view
			$sql = "DELETE FROM v_report_views WHERE report_view_uuid = :uuid";
			$this->database->execute($sql, $params);
			
			return array('success' => true);
		} catch (Exception $e) {
			return array('success' => false, 'error' => $e->getMessage());
		}
	}
	
	/**
	 * Duplicate a view
	 */
	public function duplicate_view($view_uuid, $new_name = null) {
		try {
			$view = $this->get_view($view_uuid);
			if (!$view) {
				return array('success' => false, 'error' => 'View not found');
			}
			
			$name = $new_name ? $new_name : $view['name'] . ' (Copy)';
			$description = $view['description'];
			$definition = $view['definition'];
			
			return $this->create_view($name, $description, $definition, false);
		} catch (Exception $e) {
			return array('success' => false, 'error' => $e->getMessage());
		}
	}
	
	/**
	 * Check if user can view a report
	 */
	private function can_view($view_record) {
		// Public views are accessible to all
		if ($view_record['is_public'] == 't' || $view_record['is_public'] == '1') {
			return true;
		}
		
		// Owner can always view
		if ($this->user_uuid && $view_record['created_by'] == $this->user_uuid) {
			return true;
		}
		
		// Check ACL (if implemented)
		// For now, domain match is sufficient
		if ($view_record['domain_uuid'] == $this->domain_uuid || $view_record['domain_uuid'] === null) {
			return true;
		}
		
		return false;
	}
	
	/**
	 * Check if user can edit a report
	 */
	private function can_edit($view_record) {
		// Only owner or superadmin can edit
		if ($this->user_uuid && $view_record['created_by'] == $this->user_uuid) {
			return true;
		}
		
		// Check for superadmin permission (would need to be passed in or checked)
		// For now, only owner can edit
		return false;
	}
	
	/**
	 * Check if user can delete a report
	 */
	private function can_delete($view_record) {
		// Same as edit for now
		return $this->can_edit($view_record);
	}
	
	/**
	 * Validate view definition
	 */
	private function validate_definition($definition) {
		if (!is_array($definition)) {
			return false;
		}
		
		// Check required fields
		$required_fields = array('version', 'call_type', 'date_range', 'metrics');
		foreach ($required_fields as $field) {
			if (!isset($definition[$field])) {
				return false;
			}
		}
		
		// Validate call_type
		$valid_call_types = array('any', 'inbound', 'outbound', 'local');
		if (!in_array($definition['call_type'], $valid_call_types)) {
			return false;
		}
		
		// Validate metrics array
		if (!is_array($definition['metrics']) || empty($definition['metrics'])) {
			return false;
		}
		
		// Validate date_range
		if (!isset($definition['date_range']['mode'])) {
			return false;
		}
		
		return true;
	}
	
	/**
	 * Format view record
	 */
	private function format_view_record($row) {
		return array(
			'uuid' => $row['report_view_uuid'],
			'domain_uuid' => $row['domain_uuid'],
			'name' => $row['name'],
			'description' => $row['description'],
			'definition' => json_decode($row['definition_json'], true),
			'is_public' => ($row['is_public'] == 't' || $row['is_public'] == '1'),
			'created_by' => $row['created_by'],
			'created_at' => $row['created_at'],
			'updated_at' => $row['updated_at']
		);
	}
}

?>
