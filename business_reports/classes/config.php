<?php
/*
	FusionPBX - Business Reports
	Config: Hardcoded configuration for v_xml_cdr schema
*/

class report_config {
	
	/**
	 * Get hardcoded configuration for FusionPBX v_xml_cdr
	 */
	public static function get_config() {
		return array(
			'cdr_source' => 'v_xml_cdr',
			'field_mapping' => array(
				'domain_uuid' => 'domain_uuid',
				'start_stamp' => 'start_stamp',
				'answer_stamp' => 'answer_stamp',
				'end_stamp' => 'end_stamp',
				'duration' => 'duration',
				'billsec' => 'billsec',
				'hangup_cause' => 'hangup_cause',
				'uuid' => 'uuid',
				'caller_id_number' => 'caller_id_number',
				'destination_number' => 'destination_number',
				'direction' => 'direction',
				'extension_uuid' => 'extension_uuid',
				'gateway_uuid' => 'gateway_uuid'
			),
			'call_type_mode' => 'direction_field',
			'counting_unit' => 'row',
			'call_id_field' => 'uuid',
			'config' => array()
		);
	}
}

?>
