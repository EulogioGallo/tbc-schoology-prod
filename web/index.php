<?php
 require_once('Schoology_OAuth.php');
 require('../vendor/autoload.php');
 ini_set('log_errors_max_len', 0);
 
 
 // establish connection
$SchoologyApi = new SchoologyContainer();
$SchoologyApi->schoologyOAuth();

$object_result = json_decode(file_get_contents("php://input"));	
error_log(print_r($object_result,true));
error_log(print_r($object_result->action,true));



// determine operation type and object
switch($object_result->action) {
	case 'INSERT':
		if($object_result->table == 'ram_cohort__c') {
			$SchoologyApi->createCourse($object_result);
		} else if($object_result->table == 'ram_assignment_master__c') {
			$SchoologyApi->createAssignment($object_result);
		} else if($object_result->table == 'ram_assignment__c') {
			$SchoologyApi->gradeAssignment($object_result);
		}
		break;
	case 'UPDATE':
		if($object_result->table == 'ram_cohort__c') {
			$SchoologyApi->updateCourse($object_result);
		} else if($object_result->table == 'ram_assignment_master__c') {
			$SchoologyApi->updateAssignment($object_result);
		} else if($object_result->table == 'ram_assignment__c') {
			$SchoologyApi->gradeAssignment($object_result);
		}
		break;
	case 'DELETE':
		if($object_result->table == 'ram_cohort__c') {
			$SchoologyApi->deleteCourse($object_result);
		} else if($object_result->table == 'ram_assignment_master__c') {
			$SchoologyApi->deleteAssignment($object_result);
		}
		break;
	default: // this means that Schoology is sending back info
		if(strpos($object_result->type, 'dropbox_submission') !== false) {
			$SchoologyApi->getAssignmentSubmission($object_result->data);
		}
		break;
}

?>