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

// make sure the PATH_SEPARATOR is defined
	if (!defined("PATH_SEPARATOR")) {
		if (strpos($_ENV["OS"], "Win") !== false) {
			define("PATH_SEPARATOR", ";");
		} else {
			define("PATH_SEPARATOR", ":");
		}
	}

// make sure the document_root is set
	$_SERVER["SCRIPT_FILENAME"] = str_replace("\\", "/", $_SERVER["SCRIPT_FILENAME"]);
	$_SERVER["DOCUMENT_ROOT"] = str_replace($_SERVER["SCRIPT_FILENAME"], "", $_SERVER["SCRIPT_FILENAME"]);
	$_SERVER["DOCUMENT_ROOT"] = realpath($_SERVER["DOCUMENT_ROOT"]);

?>
