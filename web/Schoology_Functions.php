<?php

class Schoology_Functions {
	public static function sendAssignmentMaster(stdClass $jsonObject) {
		error_log(print_r($jsonObject,true));
	}
	
	public static function sendAssignmentToSF(stdClass $jsonObject) {
		error_log("sendAssignmentToSF");
		error_log(print_r($jsonObject,true));
	}
}

?>