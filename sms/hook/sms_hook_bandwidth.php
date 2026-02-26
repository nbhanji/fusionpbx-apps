<?php
// show error logs
// error_reporting(E_ALL);
// ini_set('display_errors', 1);


//includes files
require_once "../sms_hook_common.php";

if (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') === 0) {
	$data = json_decode(file_get_contents("php://input"));

	http_response_code(200);
	if (function_exists('fastcgi_finish_request')) {
		fastcgi_finish_request();  // ✅ Bandwidth gets HTTP 200 immediately
	}

	if ($debug) {
		// error_log('[SMS] REQUEST: ' . print_r($data, true));
	}

	// Normalize $data to always be an array of message objects
	$messages = is_array($data) ? $data : [$data];

	foreach ($messages as $msg) {
		$message_id = $msg->message->id ?? null;
		$from = $msg->message->from ?? null;
		$to = $msg->message->owner ?? null;
		$text = $msg->message->text ?? null;
		$msg_type = $msg->type ?? null;
		$desc = $msg->description ?? null;
		$media = $msg->message->media ?? null;

		/**
		 * Bandwidth uses HTTP Callbacks webhooks to send events to any publicly addressable url, as defined in 
		 * your messaging application. All Message callbacks are sent as a list/array [ {message metadata} ] 
		 * to the webhook url in the application. You MUST Reply with a HTTP 2xx status code for every 
		 * callback/delivery receipt. Bandwidth will retry every callback over the next 24 hours until a HTTP 2xx 
		 * code is received for the callback. After 24 hours, Bandwidth will no longer try to send the callback.
		 * Bandwidth's Messaging platform has a 10 second timeout for callbacks. This means your server must 
		 * respond to the callback request within 10 seconds, otherwise the platform will try again at a later time.
		 * 
		 * https://dev.bandwidth.com/docs/messaging/webhooks#message-failed/
		 */

		switch (strtolower($msg_type)) {
			case 'message-delivered': {
					//				$text .= "Message was delivered";
					//				route_and_send_sms($from, $to, $text);
					return http_response_code(200);
					break;
				}
			case 'message-failed': {

					$text = "#$message_id Message failed to be delivered (" . $from . " to " . $to . ")";;
					$text .= " Reason: " . ucfirst(str_replace('-', ' ', $desc));
					error_log("Message Failed to send " . print_r($data, false));
					route_and_send_sms($from, $to, $text);
					break;
				}
			default: {

					$acrobitsMedia = handleBandwidthMediaForAcrobits($text, $media);
					$media = "";
					if (!empty($acrobitsMedia)) {
						$text = $acrobitsMedia['json'] ?? $text;

						if (!empty($acrobitsMedia['media_urls'])) {
							$media = $acrobitsMedia['media_urls'];
						}
					}

					route_and_send_sms($from, $to, $text, $media);
				}
		}
	}
} else {
	error_log('[SMS] REQUEST: No SMS Data Received');
	die("no");
}

function handleBandwidthMediaForAcrobits($text, $media = [])
{
	// Fetch Bandwidth credentials from the database
	global $db;
	$sql = "SELECT default_setting_subcategory AS v_name, default_setting_value AS v_value
			FROM v_default_settings
			WHERE default_setting_category = 'sms'
			  AND default_setting_subcategory IN ('bandwidth_access_key', 'bandwidth_secret_key', 'bandwidth_api_url', 'mms_attachment_temp_path')
			  AND (default_setting_enabled = true OR default_setting_enabled IS NULL)";
	$prep_statement = $db->prepare($sql);
	$prep_statement->execute();
	$settings = $prep_statement->fetchAll(PDO::FETCH_ASSOC);

	$bw_user = '';
	$bw_pass = '';
	$bw_api_url = '';
	$mms_attachment_temp_path = '';

	foreach ($settings as $row) {
		switch ($row['v_name']) {
			case 'bandwidth_access_key':
				$bw_user = $row['v_value'];
				break;
			case 'bandwidth_secret_key':
				$bw_pass = $row['v_value'];
				break;
			case 'bandwidth_api_url':
				$bw_api_url = $row['v_value'];
				break;
			case 'mms_attachment_temp_path':
				$mms_attachment_temp_path = $row['v_value'];
				break;
		}
	}

	if (empty($bw_pass)) {
		error_log("Bandwidth credentials not found from session.");
		return;
	}

	// Extract userId as the full digits between two slashes in the API URL
	if (preg_match('#/(\d+)/#', $bw_api_url, $matches)) {
		$userId = $matches[1];
	} else {
		$userId = '';
	}

	$mediaPaths = [];

	if (!empty($media) && is_array($media)) {

		$acrobitsJson = [
			'attachments' => [],
			'body' => $text
		];

		foreach ($media as $url) {
			$start = strrpos($url, '/') == -1 ? strrpos($url, '//') : strrpos($url, '/') + 1;
			$ori_fileatt_name = substr($url, $start, strlen($url)); // Filename for the file as the attachment

			$url = str_replace('u-ezcynf6rrielex2s7zo4zny', $userId, $url); // Ensure URL is in correct format

			// Generate a unique filename while preserving the original extension
			$ext = pathinfo($ori_fileatt_name, PATHINFO_EXTENSION);
			$fileatt_name = uniqid('bw_', true) . ($ext ? '.' . $ext : '');

			if (in_array($ext, ["xml", "smil"])) {
				continue;
			}

			if (!empty($mms_attachment_temp_path)) {
				$fileatt = $mms_attachment_temp_path;
				if (substr($fileatt, -1) != '/') {
					$fileatt .= '/';
				}
				$fileatt .= $fileatt_name;
			} else {
				$fileatt = '/var/www/fusionpbx/app/sms/tmp/' . $fileatt_name;
			}

			// Download the file and get headers in one request
			$opts = [
				"http" => [
					"method" => "GET",
					"header" => "Authorization: Basic " . base64_encode($bw_user . ":" . $bw_pass),
					"ignore_errors" => true
				]
			];
			$context = stream_context_create($opts);
			$stream = fopen($url, 'rb', false, $context);

			if (!$stream) {
				error_log("Failed to open media stream from Bandwidth: $url");
				continue;
			}

			// Get headers from $http_response_header
			$headers = isset($http_response_header) ? implode("\n", $http_response_header) : '';
			preg_match('/^Content-Type:\s*(.*)$/mi', $headers, $matches);
			$contentType = $matches[1] ?? 'application/octet-stream';

			// Read file content
			$original = stream_get_contents($stream);
			fclose($stream);

			if ($original === false) {
				error_log("Failed to read media from stream: $url");
				continue;
			}

			// save the original file
			$result = file_put_contents($fileatt, $original);
			if ($result === false) {
				$error = error_get_last();
				error_log("Failed to save original file to $fileatt: " . ($error['message'] ?? 'Unknown error'));
				continue;
			}

			// Build HTTP URL for the encrypted file using /var/www/fusionpbx as base dir
			$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
			$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

			// $encPath is like /var/www/fusionpbx/app/sms/tmp/filename.png.enc
			$baseDir = '/var/www/fusionpbx';
			$webBase = ''; // empty means root

			$encPathReal = realpath($fileatt);

			if ($encPathReal && strpos($encPathReal, $baseDir) === 0) {
				$relativePath = substr($encPathReal, strlen($baseDir));
				$relativePath = str_replace('\\', '/', $relativePath);
				if (substr($relativePath, 0, 1) !== '/') {
					$relativePath = '/' . $relativePath;
				}
				$fileUrl = $protocol . $host . $webBase . $relativePath;
			} else {
				// fallback: just use /app/sms/tmp/filename
				$fileUrl = $protocol . $host . '/app/sms/tmp/' . basename($fileatt);
			}

			$mediaPaths[] = [
				'name' => $fileatt_name,
				'path' => $fileatt,
			];

			$acrobitsJson['attachments'][] = [
				'content-type'   => $contentType,
				'content-url'    => $fileUrl,
				'filename'       => $fileatt_name,
				'description'    => 'Attachment from Bandwidth MMS Media',
			];
		}
	}

	// Return both JSON string and array of media URLs
	return [
		'json' => $acrobitsJson ? json_encode($acrobitsJson, JSON_UNESCAPED_SLASHES) : null,
		'media_urls' => $mediaPaths
	];
}
