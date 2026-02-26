<?php
/*
	FusionPBX - Business Reports
	Query Builder: Generates SQL queries from view definitions
*/

class query_builder {
	
	private $db_type;
	private $cdr_source;
	private $field_mapping;
	private $call_type_mode;
	private $counting_unit;
	private $call_id_field;
	private $metric_registry;
	private $domain_uuid;
	private $config;
	
	public function __construct($db_type, $diagnostics_config, $domain_uuid = null) {
		$this->db_type = $db_type;
		$this->cdr_source = $diagnostics_config['cdr_source'];
		$this->field_mapping = $diagnostics_config['field_mapping'];
		$this->call_type_mode = $diagnostics_config['call_type_mode'];
		$this->counting_unit = $diagnostics_config['counting_unit'];
		$this->call_id_field = $diagnostics_config['call_id_field'];
		$this->config = isset($diagnostics_config['config']) ? $diagnostics_config['config'] : array();
		$this->domain_uuid = $domain_uuid;
		
		$this->metric_registry = new metric_registry(
			$db_type,
			$this->field_mapping,
			$this->counting_unit,
			$this->call_id_field
		);
	}
	
	/**
	 * Build SQL query from view definition
	 */
	public function build_query($view_definition) {
		$params = array();
		
		// Build SELECT clause
		$select_fields = $this->build_select_fields($view_definition);
		
		// Build FROM clause
		$from_clause = $this->cdr_source;
		
		// Build WHERE clause
		list($where_clause, $where_params) = $this->build_where_clause($view_definition);
		$params = array_merge($params, $where_params);
		
		// Build GROUP BY clause
		$group_by_clause = $this->build_group_by_clause($view_definition);
		
		// Build ORDER BY clause
		$order_by_clause = $this->build_order_by_clause($view_definition);
		
		// Build LIMIT clause
		$limit_clause = $this->build_limit_clause($view_definition);
		
		// Assemble query
		$sql = "SELECT " . $select_fields . " FROM " . $from_clause;
		if ($where_clause) {
			$sql .= " WHERE " . $where_clause;
		}
		if ($group_by_clause) {
			$sql .= " GROUP BY " . $group_by_clause;
		}
		if ($order_by_clause) {
			$sql .= " ORDER BY " . $order_by_clause;
		}
		if ($limit_clause) {
			$sql .= " LIMIT " . $limit_clause;
		}
		
		return array(
			'sql' => $sql,
			'params' => $params,
			'post_process' => $this->get_post_process_instructions($view_definition)
		);
	}
	
	/**
	 * Build SELECT fields
	 */
	private function build_select_fields($view_definition) {
		$fields = array();
		
		// Add grouping fields
		$group_by = isset($view_definition['group_by']) ? $view_definition['group_by'] : array();
		
		if (isset($group_by['time_bucket']) && $group_by['time_bucket'] != 'none') {
			$time_field = $this->get_field('start_stamp');
			$bucket_expr = $this->get_time_bucket_expression($time_field, $group_by['time_bucket']);
			$fields[] = $bucket_expr . " as start_date";
		}
		
		if (isset($group_by['dimension']) && $group_by['dimension'] != 'none') {
			$dimension_field = $this->get_dimension_field($group_by['dimension']);
			if ($dimension_field) {
				$fields[] = $dimension_field . " as group_dimension";
			}
		}
		
		// Add metric expressions
		$metrics = isset($view_definition['metrics']) ? $view_definition['metrics'] : array();
		foreach ($metrics as $metric_id) {
			$metric = $this->metric_registry->get_metric($metric_id);
			if ($metric && isset($metric['sql_expression']) && $metric['sql_expression']) {
				$fields[] = $metric['sql_expression'] . " as " . $metric_id;
			}
		}
		
		// If no fields specified, select count
		if (empty($fields)) {
			$fields[] = "COUNT(*) as total_calls";
		}
		
		return implode(", ", $fields);
	}
	
	/**
	 * Build WHERE clause
	 */
	private function build_where_clause($view_definition) {
		$conditions = array();
		$params = array();
		
		// Always filter by domain
		if ($this->domain_uuid) {
			$domain_field = $this->get_field('domain_uuid');
			$conditions[] = $domain_field . " = :domain_uuid";
			$params['domain_uuid'] = $this->domain_uuid;
		}
		
		// Date range filter (mandatory)
		$date_range = isset($view_definition['date_range']) ? $view_definition['date_range'] : array();
		list($date_condition, $date_params) = $this->build_date_range_condition($date_range);
		if ($date_condition) {
			$conditions[] = $date_condition;
			$params = array_merge($params, $date_params);
		}
		
		// Call type filter
		if (isset($view_definition['call_type']) && $view_definition['call_type'] != 'any') {
			$call_type_condition = $this->build_call_type_condition($view_definition['call_type']);
			if ($call_type_condition) {
				$conditions[] = $call_type_condition;
			}
		}
		
		// Additional filters
		$filters = isset($view_definition['filters']) ? $view_definition['filters'] : array();
		list($filter_conditions, $filter_params) = $this->build_filter_conditions($filters);
		if (!empty($filter_conditions)) {
			$conditions = array_merge($conditions, $filter_conditions);
			$params = array_merge($params, $filter_params);
		}
		
		$where_clause = implode(" AND ", $conditions);
		return array($where_clause, $params);
	}
	
	/**
	 * Build date range condition
	 */
	private function build_date_range_condition($date_range) {
		$start_field = $this->get_field('start_stamp');
		$conditions = array();
		$params = array();
		
		$mode = isset($date_range['mode']) ? $date_range['mode'] : 'relative';
		
		if ($mode == 'relative') {
			$last_days = isset($date_range['last_days']) ? (int)$date_range['last_days'] : 7;
			
			if ($this->db_type == 'postgresql') {
				$conditions[] = $start_field . " >= NOW() - INTERVAL '" . $last_days . " days'";
			} else {
				$conditions[] = $start_field . " >= DATE_SUB(NOW(), INTERVAL " . $last_days . " DAY)";
			}
		} elseif ($mode == 'absolute') {
			if (isset($date_range['from'])) {
				$conditions[] = $start_field . " >= :date_from";
				$params['date_from'] = $date_range['from'];
			}
			if (isset($date_range['to'])) {
				$conditions[] = $start_field . " <= :date_to";
				$params['date_to'] = $date_range['to'];
			}
		}
		
		$condition = implode(" AND ", $conditions);
		return array($condition, $params);
	}
	
	/**
	 * Build call type condition
	 */
	private function build_call_type_condition($call_type) {
		// v_xml_cdr has a direction field
		if (isset($this->field_mapping['direction'])) {
			$direction_field = $this->field_mapping['direction'];
			
			switch ($call_type) {
				case 'inbound':
					return $direction_field . " = 'inbound'";
				case 'outbound':
					return $direction_field . " = 'outbound'";
				case 'local':
					return $direction_field . " IN ('local', 'internal')";
			}
		}
		
		return null;
	}
	
	/**
	 * Build filter conditions
	 */
	private function build_filter_conditions($filters) {
		$conditions = array();
		$params = array();
		
		// Extension UUID filter
		if (isset($filters['extension_uuid']) && !empty($filters['extension_uuid'])) {
			if (isset($this->field_mapping['extension_uuid'])) {
				$field = $this->field_mapping['extension_uuid'];
				$placeholders = array();
				foreach ($filters['extension_uuid'] as $idx => $uuid) {
					$param_name = 'ext_uuid_' . $idx;
					$placeholders[] = ':' . $param_name;
					$params[$param_name] = $uuid;
				}
				$conditions[] = $field . " IN (" . implode(", ", $placeholders) . ")";
			}
		}
		
		// Gateway UUID filter
		if (isset($filters['gateway_uuid']) && !empty($filters['gateway_uuid'])) {
			if (isset($this->field_mapping['gateway_uuid'])) {
				$field = $this->field_mapping['gateway_uuid'];
				$placeholders = array();
				foreach ($filters['gateway_uuid'] as $idx => $uuid) {
					$param_name = 'gw_uuid_' . $idx;
					$placeholders[] = ':' . $param_name;
					$params[$param_name] = $uuid;
				}
				$conditions[] = $field . " IN (" . implode(", ", $placeholders) . ")";
			}
		}
		
		// Hangup cause filter
		if (isset($filters['hangup_cause']) && !empty($filters['hangup_cause'])) {
			if (isset($this->field_mapping['hangup_cause'])) {
				$field = $this->field_mapping['hangup_cause'];
				$placeholders = array();
				foreach ($filters['hangup_cause'] as $idx => $cause) {
					$param_name = 'hc_' . $idx;
					$placeholders[] = ':' . $param_name;
					$params[$param_name] = $cause;
				}
				$conditions[] = $field . " IN (" . implode(", ", $placeholders) . ")";
			}
		}
		
		// Caller pattern filter
		if (isset($filters['caller_like']) && !empty($filters['caller_like'])) {
			$caller_field = $this->get_field('caller_id_number');
			if ($caller_field) {
				$conditions[] = $caller_field . " LIKE :caller_pattern";
				$params['caller_pattern'] = '%' . $this->escape_like($filters['caller_like']) . '%';
			}
		}
		
		// Destination pattern filter
		if (isset($filters['destination_like']) && !empty($filters['destination_like'])) {
			$dest_field = $this->get_field('destination_number');
			if ($dest_field) {
				$conditions[] = $dest_field . " LIKE :dest_pattern";
				$params['dest_pattern'] = '%' . $this->escape_like($filters['destination_like']) . '%';
			}
		}
		
		return array($conditions, $params);
	}
	
	/**
	 * Build GROUP BY clause
	 */
	private function build_group_by_clause($view_definition) {
		$group_fields = array();
		$group_by = isset($view_definition['group_by']) ? $view_definition['group_by'] : array();
		
		if (isset($group_by['time_bucket']) && $group_by['time_bucket'] != 'none') {
			$time_field = $this->get_field('start_stamp');
			$bucket_expr = $this->get_time_bucket_expression($time_field, $group_by['time_bucket']);
			$group_fields[] = $bucket_expr;
		}
		
		if (isset($group_by['dimension']) && $group_by['dimension'] != 'none') {
			$dimension_field = $this->get_dimension_field($group_by['dimension']);
			if ($dimension_field) {
				$group_fields[] = $dimension_field;
			}
		}
		
		return empty($group_fields) ? null : implode(", ", $group_fields);
	}
	
	/**
	 * Build ORDER BY clause
	 */
	private function build_order_by_clause($view_definition) {
		$sort = isset($view_definition['sort']) ? $view_definition['sort'] : array();
		$sort_by = isset($sort['by']) ? $sort['by'] : 'start_date';
		$sort_dir = isset($sort['dir']) ? strtoupper($sort['dir']) : 'DESC';
		
		// Validate sort direction
		if ($sort_dir != 'ASC' && $sort_dir != 'DESC') {
			$sort_dir = 'DESC';
		}
		
		// Validate sort_by against allowed fields to prevent SQL injection
		$allowed_sort_fields = array('start_date', 'group_dimension', 'total_calls', 'connected_calls', 
									  'not_connected_calls', 'talk_time_sec', 'asr', 'acd_sec', 
									  'avg_ring_sec', 'no_answer_calls', 'busy_calls', 'failed_calls');
		
		if (!in_array($sort_by, $allowed_sort_fields)) {
			$sort_by = 'start_date'; // Default to safe value
		}
		
		return $sort_by . " " . $sort_dir;
	}
	
	/**
	 * Build LIMIT clause
	 */
	private function build_limit_clause($view_definition) {
		$limit = isset($view_definition['limit']) ? (int)$view_definition['limit'] : 1000;
		
		// Hard cap at 10000
		if ($limit > 10000) {
			$limit = 10000;
		}
		
		return (string)$limit;
	}
	
	/**
	 * Get time bucket expression
	 */
	private function get_time_bucket_expression($field, $bucket_type) {
		switch ($bucket_type) {
			case 'day':
				if ($this->db_type == 'postgresql') {
					return "DATE(" . $field . ")";
				} else {
					return "DATE(" . $field . ")";
				}
			case 'hour':
				if ($this->db_type == 'postgresql') {
					return "DATE_TRUNC('hour', " . $field . ")";
				} else {
					return "DATE_FORMAT(" . $field . ", '%Y-%m-%d %H:00:00')";
				}
			case 'weekday':
				if ($this->db_type == 'postgresql') {
					return "EXTRACT(DOW FROM " . $field . ")";
				} else {
					return "DAYOFWEEK(" . $field . ")";
				}
			default:
				return $field;
		}
	}
	
	/**
	 * Get dimension field
	 */
	private function get_dimension_field($dimension) {
		switch ($dimension) {
			case 'extension':
				return $this->get_field('extension_uuid');
			case 'did':
				return $this->get_field('destination_number');
			case 'gateway':
				return $this->get_field('gateway_uuid');
			case 'hangup_cause':
				return $this->get_field('hangup_cause');
			default:
				return null;
		}
	}
	
	/**
	 * Get field name from mapping
	 */
	private function get_field($logical_name) {
		return isset($this->field_mapping[$logical_name]) ? $this->field_mapping[$logical_name] : $logical_name;
	}
	
	/**
	 * Escape LIKE wildcard characters
	 */
	private function escape_like($string) {
		$string = str_replace('\\', '\\\\', $string);
		$string = str_replace('%', '\\%', $string);
		$string = str_replace('_', '\\_', $string);
		return $string;
	}
	
	/**
	 * Escape SQL identifier (for LIKE patterns)
	 */
	private function escape_sql_identifier($string) {
		// Remove any single quotes and backslashes for safety
		return str_replace(array("'", "\\"), array("''", "\\\\"), $string);
	}
	
	/**
	 * Get post-processing instructions
	 */
	private function get_post_process_instructions($view_definition) {
		$instructions = array();
		$metrics = isset($view_definition['metrics']) ? $view_definition['metrics'] : array();
		
		// Check which metrics need post-processing
		foreach ($metrics as $metric_id) {
			$metric = $this->metric_registry->get_metric($metric_id);
			if ($metric && isset($metric['computed']) && $metric['computed']) {
				$instructions[] = array(
					'metric' => $metric_id,
					'compute_from' => $metric['compute_from']
				);
			}
		}
		
		return $instructions;
	}
}

?>
