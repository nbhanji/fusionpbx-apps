<?php
/*
	FusionPBX - Business Reports
	Metric Registry: Defines available metrics and their SQL expressions
*/

class metric_registry {
	
	private $db_type;
	private $field_mapping;
	private $counting_unit;
	private $call_id_field;
	
	public function __construct($db_type, $field_mapping, $counting_unit = 'row', $call_id_field = 'uuid') {
		$this->db_type = $db_type;
		$this->field_mapping = $field_mapping;
		$this->counting_unit = $counting_unit;
		$this->call_id_field = $call_id_field;
	}
	
	/**
	 * Get all available metrics
	 */
	public function get_all_metrics() {
		return array(
			'total_calls' => $this->metric_total_calls(),
			'connected_calls' => $this->metric_connected_calls(),
			'not_connected_calls' => $this->metric_not_connected_calls(),
			'talk_time_sec' => $this->metric_talk_time(),
			'asr' => $this->metric_asr(),
			'acd_sec' => $this->metric_acd(),
			'avg_ring_sec' => $this->metric_avg_ring_time(),
			'no_answer_calls' => $this->metric_no_answer_calls(),
			'busy_calls' => $this->metric_busy_calls(),
			'failed_calls' => $this->metric_failed_calls()
		);
	}
	
	/**
	 * Get metric definition by ID
	 */
	public function get_metric($metric_id) {
		$all_metrics = $this->get_all_metrics();
		return isset($all_metrics[$metric_id]) ? $all_metrics[$metric_id] : null;
	}
	
	/**
	 * Check if metric is available based on field mapping
	 */
	public function is_metric_available($metric_id) {
		$metric = $this->get_metric($metric_id);
		if (!$metric) {
			return false;
		}
		
		foreach ($metric['required_fields'] as $field) {
			if (!isset($this->field_mapping[$field])) {
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * Get SQL expression for a metric
	 */
	public function get_sql_expression($metric_id) {
		$metric = $this->get_metric($metric_id);
		if (!$metric || !$this->is_metric_available($metric_id)) {
			return null;
		}
		
		return $metric['sql_expression'];
	}
	
	/**
	 * Total Calls metric
	 */
	private function metric_total_calls() {
		if ($this->counting_unit == 'call' && $this->call_id_field) {
			$call_field = isset($this->field_mapping[$this->call_id_field]) ? $this->field_mapping[$this->call_id_field] : $this->call_id_field;
			$expression = "COUNT(DISTINCT " . $call_field . ")";
		} else {
			$expression = "COUNT(*)";
		}
		
		return array(
			'id' => 'total_calls',
			'label' => 'Total Calls',
			'sql_expression' => $expression,
			'required_fields' => array(),
			'format' => 'number',
			'description' => 'Total number of call attempts'
		);
	}
	
	/**
	 * Connected Calls metric
	 */
	private function metric_connected_calls() {
		$billsec_field = isset($this->field_mapping['billsec']) ? $this->field_mapping['billsec'] : 'billsec';
		$expression = "SUM(CASE WHEN " . $billsec_field . " > 0 THEN 1 ELSE 0 END)";
		
		return array(
			'id' => 'connected_calls',
			'label' => 'Connected Calls',
			'sql_expression' => $expression,
			'required_fields' => array('billsec'),
			'format' => 'number',
			'description' => 'Number of answered calls'
		);
	}
	
	/**
	 * Not Connected Calls metric
	 */
	private function metric_not_connected_calls() {
		$billsec_field = isset($this->field_mapping['billsec']) ? $this->field_mapping['billsec'] : 'billsec';
		$expression = "SUM(CASE WHEN " . $billsec_field . " = 0 OR " . $billsec_field . " IS NULL THEN 1 ELSE 0 END)";
		
		return array(
			'id' => 'not_connected_calls',
			'label' => 'Not Connected',
			'sql_expression' => $expression,
			'required_fields' => array('billsec'),
			'format' => 'number',
			'description' => 'Number of unanswered calls'
		);
	}
	
	/**
	 * Talk Time metric
	 */
	private function metric_talk_time() {
		$billsec_field = isset($this->field_mapping['billsec']) ? $this->field_mapping['billsec'] : 'billsec';
		$expression = "SUM(" . $billsec_field . ")";
		
		return array(
			'id' => 'talk_time_sec',
			'label' => 'Talk Time',
			'sql_expression' => $expression,
			'required_fields' => array('billsec'),
			'format' => 'duration',
			'description' => 'Total talk time in seconds'
		);
	}
	
	/**
	 * ASR (Answer Seizure Ratio) metric
	 * Calculated in post-processing: connected_calls / total_calls
	 */
	private function metric_asr() {
		return array(
			'id' => 'asr',
			'label' => 'Answer Seizure Ratio %',
			'sql_expression' => null, // Computed in post-processing
			'required_fields' => array('billsec'),
			'format' => 'percentage',
			'description' => 'Answer Seizure Ratio (percentage of answered calls)',
			'computed' => true,
			'compute_from' => array('connected_calls', 'total_calls')
		);
	}
	
	/**
	 * ACD (Average Call Duration) metric
	 * Calculated in post-processing: talk_time_sec / connected_calls
	 */
	private function metric_acd() {
		return array(
			'id' => 'acd_sec',
			'label' => 'Average Call Duration',
			'sql_expression' => null, // Computed in post-processing
			'required_fields' => array('billsec'),
			'format' => 'duration',
			'description' => 'Average Call Duration (average length of connected calls)',
			'computed' => true,
			'compute_from' => array('talk_time_sec', 'connected_calls')
		);
	}
	
	/**
	 * Average Ring Time metric
	 */
	private function metric_avg_ring_time() {
		if (!isset($this->field_mapping['answer_stamp']) || !isset($this->field_mapping['start_stamp'])) {
			return array(
				'id' => 'avg_ring_sec',
				'label' => 'Avg Ring Time',
				'sql_expression' => null,
				'required_fields' => array('answer_stamp', 'start_stamp'),
				'format' => 'duration',
				'description' => 'Average ring time for answered calls',
				'available' => false
			);
		}
		
		$answer_field = $this->field_mapping['answer_stamp'];
		$start_field = $this->field_mapping['start_stamp'];
		$billsec_field = isset($this->field_mapping['billsec']) ? $this->field_mapping['billsec'] : 'billsec';
		
		if ($this->db_type == 'postgresql') {
			$expression = "AVG(EXTRACT(EPOCH FROM (" . $answer_field . " - " . $start_field . "))) FILTER (WHERE " . $billsec_field . " > 0)";
		} else {
			$expression = "AVG(CASE WHEN " . $billsec_field . " > 0 THEN TIMESTAMPDIFF(SECOND, " . $start_field . ", " . $answer_field . ") END)";
		}
		
		return array(
			'id' => 'avg_ring_sec',
			'label' => 'Avg Ring Time',
			'sql_expression' => $expression,
			'required_fields' => array('answer_stamp', 'start_stamp', 'billsec'),
			'format' => 'duration',
			'description' => 'Average ring time for answered calls'
		);
	}
	
	/**
	 * No Answer Calls metric
	 */
	private function metric_no_answer_calls() {
		if (!isset($this->field_mapping['hangup_cause'])) {
			return array(
				'id' => 'no_answer_calls',
				'label' => 'No Answer',
				'sql_expression' => null,
				'required_fields' => array('hangup_cause'),
				'format' => 'number',
				'description' => 'Calls that were not answered',
				'available' => false
			);
		}
		
		$hangup_field = $this->field_mapping['hangup_cause'];
		$expression = "SUM(CASE WHEN " . $hangup_field . " IN ('NO_ANSWER', 'ORIGINATOR_CANCEL', 'NO_USER_RESPONSE') THEN 1 ELSE 0 END)";
		
		return array(
			'id' => 'no_answer_calls',
			'label' => 'No Answer',
			'sql_expression' => $expression,
			'required_fields' => array('hangup_cause'),
			'format' => 'number',
			'description' => 'Calls that were not answered'
		);
	}
	
	/**
	 * Busy Calls metric
	 */
	private function metric_busy_calls() {
		if (!isset($this->field_mapping['hangup_cause'])) {
			return array(
				'id' => 'busy_calls',
				'label' => 'Busy',
				'sql_expression' => null,
				'required_fields' => array('hangup_cause'),
				'format' => 'number',
				'description' => 'Calls that encountered busy signal',
				'available' => false
			);
		}
		
		$hangup_field = $this->field_mapping['hangup_cause'];
		$expression = "SUM(CASE WHEN " . $hangup_field . " IN ('USER_BUSY', 'CALL_REJECTED') THEN 1 ELSE 0 END)";
		
		return array(
			'id' => 'busy_calls',
			'label' => 'Busy',
			'sql_expression' => $expression,
			'required_fields' => array('hangup_cause'),
			'format' => 'number',
			'description' => 'Calls that encountered busy signal'
		);
	}
	
	/**
	 * Failed Calls metric
	 */
	private function metric_failed_calls() {
		if (!isset($this->field_mapping['hangup_cause'])) {
			return array(
				'id' => 'failed_calls',
				'label' => 'Failed',
				'sql_expression' => null,
				'required_fields' => array('hangup_cause'),
				'format' => 'number',
				'description' => 'Calls that failed',
				'available' => false
			);
		}
		
		$hangup_field = $this->field_mapping['hangup_cause'];
		$expression = "SUM(CASE WHEN " . $hangup_field . " IN ('CALL_FAILED', 'NETWORK_OUT_OF_ORDER', 'RECOVERY_ON_TIMER_EXPIRE') THEN 1 ELSE 0 END)";
		
		return array(
			'id' => 'failed_calls',
			'label' => 'Failed',
			'sql_expression' => $expression,
			'required_fields' => array('hangup_cause'),
			'format' => 'number',
			'description' => 'Calls that failed'
		);
	}
	
	/**
	 * Format a metric value for display
	 */
	public function format_value($metric_id, $value) {
		$metric = $this->get_metric($metric_id);
		if (!$metric) {
			return $value;
		}
		
		switch ($metric['format']) {
			case 'duration':
				return $this->format_duration($value);
			case 'percentage':
				return number_format($value, 2) . '%';
			case 'number':
			default:
				return number_format($value);
		}
	}
	
	/**
	 * Format duration as HH:MM:SS
	 */
	private function format_duration($seconds) {
		if ($seconds === null || $seconds < 0) {
			return '00:00:00';
		}
		
		$hours = floor($seconds / 3600);
		$minutes = floor(($seconds % 3600) / 60);
		$secs = $seconds % 60;
		
		return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
	}
}

?>
