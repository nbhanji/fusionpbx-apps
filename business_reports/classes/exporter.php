<?php
/*
	FusionPBX - Business Reports
	Exporter: Export report results to various formats
*/

class report_exporter {
	
	private $metric_registry;
	
	public function __construct($metric_registry) {
		$this->metric_registry = $metric_registry;
	}
	
	/**
	 * Export results to CSV
	 */
	public function export_csv($results, $view_definition, $filename = null) {
		if (empty($results)) {
			return false;
		}
		
		// Set filename
		if (!$filename) {
			$filename = 'business_report_' . date('Y-m-d_His') . '.csv';
		}
		
		// Set headers for download
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=' . $filename);
		header('Pragma: no-cache');
		header('Expires: 0');
		
		// Open output stream
		$output = fopen('php://output', 'w');
		
		// Write UTF-8 BOM for Excel compatibility
		fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
		
		// Get column headers
		$headers = $this->get_column_headers($results[0], $view_definition);
		fputcsv($output, $headers);
		
		// Write data rows
		foreach ($results as $row) {
			$csv_row = $this->format_row_for_csv($row, $view_definition);
			fputcsv($output, $csv_row);
		}
		
		fclose($output);
		return true;
	}
	
	/**
	 * Get column headers
	 */
	private function get_column_headers($sample_row, $view_definition) {
		$headers = array();
		
		// Add grouping column headers
		if (isset($sample_row['start_date'])) {
			$headers[] = 'Date';
		}
		
		if (isset($sample_row['group_dimension'])) {
			$group_by = isset($view_definition['group_by']) ? $view_definition['group_by'] : array();
			$dimension = isset($group_by['dimension']) ? $group_by['dimension'] : 'Dimension';
			$headers[] = ucfirst($dimension);
		}
		
		// Add metric headers
		$metrics = isset($view_definition['metrics']) ? $view_definition['metrics'] : array();
		foreach ($metrics as $metric_id) {
			$metric = $this->metric_registry->get_metric($metric_id);
			if ($metric) {
				$headers[] = $metric['label'];
			}
		}
		
		return $headers;
	}
	
	/**
	 * Format a row for CSV output
	 */
	private function format_row_for_csv($row, $view_definition) {
		$csv_row = array();
		
		// Add grouping values
		if (isset($row['start_date'])) {
			$csv_row[] = $row['start_date'];
		}
		
		if (isset($row['group_dimension'])) {
			$csv_row[] = $row['group_dimension'];
		}
		
		// Add metric values
		$metrics = isset($view_definition['metrics']) ? $view_definition['metrics'] : array();
		foreach ($metrics as $metric_id) {
			if (isset($row[$metric_id])) {
				// Format value appropriately
				$value = $this->format_value_for_export($metric_id, $row[$metric_id]);
				$csv_row[] = $value;
			} else {
				$csv_row[] = '';
			}
		}
		
		return $csv_row;
	}
	
	/**
	 * Format a value for export
	 */
	private function format_value_for_export($metric_id, $value) {
		$metric = $this->metric_registry->get_metric($metric_id);
		if (!$metric) {
			return $value;
		}
		
		switch ($metric['format']) {
			case 'duration':
				// Export duration as HH:MM:SS
				return $this->metric_registry->format_value($metric_id, $value);
			case 'percentage':
				// Export percentage as decimal
				return number_format($value, 2);
			case 'number':
			default:
				// Export numbers without formatting
				return $value;
		}
	}
	
	/**
	 * Export results as JSON (for API use)
	 */
	public function export_json($results, $view_definition) {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array(
			'success' => true,
			'view' => array(
				'call_type' => $view_definition['call_type'],
				'date_range' => $view_definition['date_range'],
				'metrics' => $view_definition['metrics']
			),
			'results' => $results,
			'count' => count($results)
		), JSON_PRETTY_PRINT);
	}
}

?>
