<?php
	// Schoology SDK
	require_once('/app/schoology_php_sdk-master/SchoologyApi.class.php');
	require_once('/app/schoology_php_sdk-master/SchoologyContentApi.class.php');
	require_once('/app/schoology_php_sdk-master/SchoologyExceptions.php');
	require_once('/app/salesforce/soapclient/SforceEnterpriseClient.php');
	
	// Storage class
	class SchoologyStorage implements SchoologyApi_OauthStorage {
	  //private $db; SHOULD REMAIN PRIVATE
	  public $db;
	  private $dbHost = 'host=ec2-50-17-217-166.compute-1.amazonaws.com';
	  private $dbName = 'dbname=daka2f1gn3kb3j';
	  private $dbUser = 'qcjnvylighdjod';
	  private $dbPassword = '4412f62ecfc5fddf5f159d87e33e8480d2438b67370091dd29efb60eb6b84a11';
	 
	  public function __construct(){
		// heroku connect db
		$this->db = new PDO('pgsql:' . $this->dbHost . ';' . $this->dbName, $this->dbUser, $this->dbPassword);
		$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	 
		$query = $this->db->query("SELECT * FROM oauth_tokens");

		if(!$query) {
		  throw new Exception("Could not connect to DB\r\n");
		}
	  } 	 
	 
	  public function getAccessTokens($uid) {
		error_log('In getAccessTokens');
		$query = $this->db->prepare("SELECT * FROM oauth_tokens WHERE uid = :uid AND token_is_access = TRUE LIMIT 1");
		$query->execute(array(':uid' => $uid));
	 
		return $query->fetch(PDO::FETCH_ASSOC);
	  }
	 
	  public function saveRequestTokens($uid, $token_key, $token_secret) {
		// check if we have request token already to update instead
		$query = $this->getRequestTokens($uid);
		
		if(!$query) {
		  $query = $this->db->prepare("INSERT INTO oauth_tokens (uid, token_key, token_secret, token_is_access) VALUES(:uid, :key, :secret, FALSE)");
		  $res = $query->execute(array(':uid' => $uid, ':key' => $token_key, ':secret' => $token_secret));
		  
		} else {
		  $query = $this->db->prepare("UPDATE oauth_tokens SET token_key = :key, token_secret = :secret, token_is_access = FALSE WHERE uid = :uid");
		  $res = $query->execute(array(':uid' => $uid, ':key' => $token_key, ':secret' => $token_secret));
		}

		error_log('Saved request token!');
	  }
	 
	  public function getRequestTokens($uid) {
		$query = $this->db->prepare("SELECT * FROM oauth_tokens WHERE uid = :uid AND token_is_access = FALSE");
		$query->execute(array(':uid' => $uid));
		
		return $query->fetch(PDO::FETCH_ASSOC);
	  }
	 
	  public function requestToAccessTokens($uid, $token_key, $token_secret) {
		$query = $this->db->prepare("UPDATE oauth_tokens SET token_key = :key, token_secret = :secret, token_is_access = TRUE WHERE uid = :uid");
		$query->execute(array(':key' => $token_key, ':secret' => $token_secret, ':uid' => $uid));
	  }
	 
	  public function revokeAccessTokens($uid) {
		$query = $this->db->prepare("DELETE FROM oauth_tokens WHERE uid = :uid");
		$query->execute(array(':uid' => $uid));
	  }
	  
	  public function getDbHost() {
		  return $this->dbHost;
	  }
	  
	  public function getDbName() {
		  return $this->dbName;
	  }
	  
	  public function getDbUser() {
		  return $this->dbUser;
	  }
	  
	  public function getDbPassword() {
		  return $this->dbPassword;
	  }
	  
	}

	// Schoology API / Authorization class
	class SchoologyContainer {
		// Eulogio's Schoology Stuffs
		private $schoology_secret = 'da12e9775938fc21a144e8d39292f10a';
		private $schoology_key = '1dd53b528230ad488c8545fa1c263dba05568d1e8';
		private $schoology_uid = '22135108';
		private $httpSuccessCodes = array(200, 201, 202, 203, 204); //Not all Values Covered
		public $schoology;
		public $storage;
		public $token;
		
		public function __construct() {
			$this->schoology = new SchoologyApi($this->schoology_key, $this->schoology_secret, '', '', '', TRUE);
			$this->storage = new SchoologyStorage();
			 
			$this->token = $this->storage->getAccessTokens($this->schoology_uid);
		}
		
		public function getSchoologySecret() {
			return $this->schoology_secret;
		}
		
		public function getSchoologyKey() {
			return $this->schoology_key;
		}
		
		public function schoologyOAuth() {
			if($this->token) {
				error_log('Found token!');
			  $this->schoology->setKey($token['token_key']);
			  $this->schoology->setSecret($token['token_secret']);
			
			return true;

			} else {
				if(!isset($_GET['oauth_token'])) {
					error_log('Requesting token...');
				  $api_result = $this->schoology->api('/oauth/request_token');
				  $result = array();
				  parse_str($api_result->result, $result);
			 
				  $this->storage->saveRequestTokens($this->schoology_uid, $result['oauth_token'], $result['oauth_token_secret']);
			 
				  return true;

				} else {
					// get existing record from DB
					$request_tokens = $this->storage->getRequestTokens($schoology_uid);

					// If the token doesn't match what we have in the DB, someone's tampering with the requests
					if($request_tokens['token_key'] !== $_GET['oauth_token']) {
						throw new Exception('Invalid oauth_token received');
					}

					error_log('Have request token, converting to access...');
					// Request access tokens using our newly approved request tokens
					$this->schoology->setKey($request_tokens['token_key']);
					$this->schoology->setSecret($request_tokens['token_secret']);
					$api_result = $this->schoology->api('/oauth/access_token');

					// Parse the query-string-formatted result
					$result = array();
					parse_str($api_result->result, $result);

					// Update our DB to replace the request tokens with access tokens
					$this->storage->requestToAccessTokens($this->schoology_uid, $result['oauth_token'], $result['oauth_token_secret']);

					// Update our $ouath credentials and proceed normally
					$this->schoology->setKey($result['oauth_token']);
					$this->schoology->setSecret($result['oauth_token_secret']);
					
					error_log('Access token found!');
					
					return true;
				}
			   
			}
		}
		
		/**
		 * Creates a Schoology Course
		 *
		 * @param JSON   $newCourse Salesforce Cohort 
		 * 
		 * @throws "Invalid Course Data" If $newCourse is invalid
		 * @throws "Exception When Making API Call" If API call is unsuccessful
		 * @author Eulogio Gallo <egallo@broadcenter.org>
		 * @return
		 */ 
		public function createCourse($newCourse) {
		  if(!$newCourse) {
			  error_log('Error! Invalid data for creating course');
			  error_log(print_r($newCourse,true));
			  throw new Exception('Invalid Course data');
		  }
		  
			$courseOptions = array(
			"title" => $newCourse->data->name, 
			"course_code" => $newCourse->data->schoology_course_code__c, 
			"description" => $newCourse->data->description__c
			);
		
		  try {
			$api_result = $this->schoology->api('/courses', 'POST', $courseOptions);
			error_log(print_r($api_result,true));
		  } catch(Exception $e) {
			  error_log('Exception when making API call');
			  error_log($e->getMessage());
		  }
		  
		  // successful call result
		  if($api_result != null && in_array($api_result->http_code, $this->httpSuccessCodes)) {
			  $query = $this->storage->db->prepare("UPDATE salesforce.ram_cohort__c SET synced_to_schoology__c = TRUE, publish__c = FALSE, schoology_id__c = :schoology_id WHERE sfid = :sfid");
			  $schoologyId = $api_result->result->id;
			  error_log('Id: ' . $schoologyId);
			  if($query->execute(array(':schoology_id' => "$schoologyId", ':sfid' => $newCourse->data->sfid))) {
				  error_log('Success! Created Course ' . $newCourse->data->name . ' with ID: ' . $api_result->result->id);
				  return true;
			  } else {
				  error_log('Could not create Course ' . $newCourse->data->name);
				  throw new Exception('Could not create Course');
			  }
		  }
		}
		  
		 /**
		 * Updates a Schoology Course
		 *
		 * @param JSON   $thisCourse Salesforce Cohort 
		 * 
		 * @throws "Invalid Course Data" If $thisCourse is invalid
		 * @throws "Exception When Making API Call" If API call is unsuccessful
		 * @author Eulogio Gallo <egallo@broadcenter.org>
		 * @return
		 */ 
		public function updateCourse($thisCourse) {
			  if(!$thisCourse) {
					error_log('Error! Invalid data for updating course');
					error_log(print_r($thisCourse,true));
					throw new Exception('Invalid Course data');
			  }
			  
				$courseOptions = array(
				"title" => $thisCourse->data->name, 
				"course_code" => $thisCourse->data->schoology_course_code__c, 
				"description" => $thisCourse->data->description__c
				);
			  
			 try {
			 	$api_result = $this->schoology->api('/courses/' . $thisCourse->data->schoology_id__c, 'PUT', $courseOptions);
				error_log(print_r($api_result,true));
			  } catch(Exception $e) {
				  error_log('Exception when making API call');
				  error_log($e->getMessage());
			  }
			  
			  // successful call result
			  if($api_result != null && in_array($api_result->http_code, $this->httpSuccessCodes)) {
				  $query = $this->storage->db->prepare("UPDATE salesforce.ram_cohort__c SET synced_to_schoology__c = TRUE, publish__c = FALSE WHERE sfid = :sfid");
				  if($query->execute(array(':sfid' => $thisCourse->data->sfid))) {
					  error_log('Success! Updated Course ' . $thisCourse->data->name . ' with ID: ' . $api_result->result->id);
					  return true;
				  } else {
					  error_log('Could not update Course ' . $thisCourse->data->name);
					  throw new Exception('Could not update Course');
				  }
			  }	
		}
		  
		 /**
		 * Deletes a Schoology Course
		 *
		 * @param JSON   $thisCourse Salesforce Cohort
		 * 
		 * @throws "Invalid Course Data" If $thisCourse is invalid
		 * @throws "Exception When Making API Call" If API call is unsuccessful
		 * @author Eulogio Gallo <egallo@broadcenter.org>
		 * @return
		 */ 
		public function deleteCourse($thisCourse) {
			if(!$thisCourse) {
					error_log('Error! Invalid data for deleting course');
					error_log(print_r($thisCourse,true));
					throw new Exception('Invalid Course data');
			  }
			  
			  try {
				$api_result = $this->schoology->api('/courses/' . $thisCourse->data->schoology_id__c, 'DELETE');
				error_log(print_r($api_result,true));
			  } catch(Exception $e) {
				  error_log('Exception when making API call');
				  error_log($e->getMessage());
			  }
			  
			  // successful call result
			  if($api_result != null  && in_array($api_result->http_code, $this->httpSuccessCodes)) {
				  error_log('Success! Deleted Course ' . $thisCourse->data->name . ' with ID: ' . $thisCourse->data->schoology_id__c);
			  }
		}
		/**
		 * Creates a Schoology Assignment
		 *
		 * @param JSON   $newAss Salesforce Assignment 
		 * 
		 * @throws "Invalid Assignment Data" If $newAss is invalid
		 * @throws "Exception When Making API Call" If API call is unsuccessful
		 * @author Eulogio Gallo <egallo@broadcenter.org>
		 * @return
		 */ 
		public function createAssignment($newAss) {
			if(!$newAss) {
				error_log('Error! Invalid data for creating assignment');
				error_log(print_r($newAss,true));
				throw new Exception('Invalid Assignment data');
			}
			
			$assOptions = array(
				"title" => $newAss->data->assignment_title__c,
				"description" => $newAss->data->assignment_description__c,
				"due" => $newAss->data->due_date__c,
				//"grading_scale" => ,
				"grading_period" => 435422,
				//"grading_category" => ,
				//"allow_dropbox" => ,
				"published" => $newAss->data->publish__c,
				"type" => "assignment",
				"course_fid" => 83398284,
				//"assignees" => 
			);
			
			error_log(print_r($newAss));
			
			try {
				$api_result = $this->schoology->api('/sections/'.$newAss->data->schoology_course_id__c.'/assignments', 'POST', $assOptions);
				error_log(print_r($api_result,true));
			} catch(Exceptioni $e) {
				error_log('Exception when making API call');
				error_log($e->getMessage());
			}
			
			// successful call result
			if($api_result != null && in_array($api_result->http_code, $this->httpSuccessCodes)) {
				$query = $this->storage->db->prepare("UPDATE salesforce.ram_assignment_master__c SET synced_to_schoology__c = TRUE, publish__c = FALSE, schoology_id__c = :schoology_id WHERE sfid = :sfid");
				$schoologyId = $api_result->result->id;
				error_log('Id: ' . $schoologyId);
				if($query->execute(array(':schoology_id' => "$schoologyId", ':sfid' => $newAss->data->sfid))) {
					error_log('Success! Created Assignment ' . $newAss->data->assignment_title__c . ' with ID: ' . $api_result->result->id);
					return true;
				} else {
					error_log('Could not create Assignment ' . $newAss->data->assignment_title__c);
					throw new Exception('Could not create Assignment');
				}
			}
		}
		
		/**
		 * Updates a Schoology Assignment
		 *
		 * @param JSON   $thisAss Salesforce Assignment 
		 * 
		 * @throws "Invalid Assignment Data" If $thisAss is invalid
		 * @throws "Exception When Making API Call" If API call is unsuccessful
		 * @author Eulogio Gallo <egallo@broadcenter.org>
		 * @return
		 */ 
		public function updateAssignment($thisAss) {
			if(!$thisAss) {
				error_log('Error! Invalid data for updating assignment');
				error_log(print_r($thisAss,true));
				throw new Exception('Invalid Assignment data');
			}
			
			$assOptions = array(
				"title" => $thisAss->data->assignment_title__c,
				"description" => $thisAss->data->assignment_description__c,
				"due" => $thisAss->data->due_date__c,
				//"grading_scale" => ,
				"grading_period" => 435422, // static for now, need to pass this from SF
				//"grading_category" => ,
				//"allow_dropbox" => ,
				"published" => $thisAss->data->publish__c,
				"type" => "assignment",
				"course_fid" => 83398284, // static for now, need to pass this from SF		
				//"assignees" => 
			);
			
			try {
				$api_result = $this->schoology->api('/sections/'.$thisAss->data->schoology_course_id__c.'/assignments/'.$thisAss->data->schoology_id__c, 'PUT', $assOptions);
				error_log(print_r($api_result,true));
			} catch(Exception $e) {
				error_log('Exception when making API call');
				error_log($e->getMessage());
			}		
			// successful call result
			if($api_result != null && in_array($api_result->http_code, $this->httpSuccessCodes)) {
				$query = $this->storage->db->prepare("UPDATE salesforce.ram_assignment_master__c SET synced_to_schoology__c = TRUE, publish__c = FALSE WHERE sfid = :sfid");
				if($query->execute(array(':sfid' => $thisAss->data->sfid))) {
					error_log('Success! Updated Assignment ' . $thisAss->data->assignment_title__c . ' with ID: ' . $api_result->result->id);
					return true;
				} else {
					error_log('Could not update Assignment ' . $thisAss->data->assignment_title__c);
					throw new Exception('Could not update Assignment');
				}
			}
		}
		
		/**
		 * Deletes a Schoology Assignment
		 *
		 * @param JSON   $thisAss Salesforce Assignment 
		 * 
		 * @throws "Invalid Assignment Data" If $thisAss is invalid
		 * @throws "Exception When Making API Call" If API call is unsuccessful
		 * @author Eulogio Gallo <egallo@broadcenter.org>
		 * @return
		 */ 
		public function deleteAssignment($thisAss) {
			if(!$thisAss) {
				error_log('Error! Invalid data for deleting Assignment');
				error_log(print_r($thisAss,true));
				throw new Exception('Invalid Assignment data');
			}
			
			try {
				$api_result = $this->schoology->api('/sections/'.$thisAss->data->schoology_course_id__c.'/assignments/'.$thisAss->data->schoology_id__c, 'DELETE');
				error_log(print_r($api_result,true));
			} catch(Exception $e) {
				error_log('Exception when making API call');
				error_log($e->getMessage());
			}
			
			// successful call result
			if($api_result != null && in_array($api_result->http_code, $this->httpSuccessCodes)) {
				error_log('Success! Deleted Assignment ' . $thisAss->data->assignment_title__c . ' with ID: ' .$thisAss->data->schoology_id__c);
			}
		}
		
		/**
		 * Retrieves an Assignment submission from Schoology
		 *
		 * @param JSON   $thisAss Schoology Assignment
		 * 
		 * @throws "Invalid Assignment Data" If $thisAss is invalid
		 * @throws "Exception When Making API Call" If API call is unsuccessful
		 * @author Edgar Lopez <elopez@broadcenter.org>
		 * @return
		 */ 
		public function getAssignmentSubmission($thisAss) {
			if(!$thisAss) {
				error_log('Error! Invalid data for Retrieving Assignment Submission');
				error_log(print_r($thisAss,true));
				throw new Exception('Invalid data for Retrieving Submission');
			}

			//Logging into Salesforce
			try{
			$mySforceConnection = new SforceEnterpriseClient();
			$mySoapClient = $mySforceConnection->createConnection("/app/tbc_wsdl.xml");
			$mylogin = $mySforceConnection->login("egallo@broadcenter.org", "TBCfosho2015!PXa3wr5xilaCKglSdkw9tfOvH");
			error_log('Connecting to Salesforce. . .');
			} catch(Exception $e){
				error_log('Error Connecting to Salesforce!');
				error_log($e->faultstring);
			}

			//Begin at JSON elements of the first attached file
			reset($thisAss->object->attachments->files->file);
			
			do {//For each attached file
				//error_log(current($thisAss->object->attachments->files->file)->id);
				$downloadPath = current($thisAss->object->attachments->files->file)->download_path;
				
				//Case Handling: Inconsistancy in JSON Response, (converted_download_path vs. download_path(inaccesssible)) 
				//error_log($downloadPath);

				$initialType  = current($thisAss->object->attachments->files->file)->filemime;
				$initialName  = current($thisAss->object->attachments->files->file)->filename;
				$subType = 'no content';

				//error_log(print_r($initialType,true));

				switch($initialType){
					//Word Documents
					case'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
					case'application/msword':
					case'application/vnd.google-apps.document':
					case'application/vnd.ms-word.document.macroEnabled.12':
					case'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
					case'application/vnd.openxmlformats-officedocument.wordprocessingml.template':
					case'application/vnd.oasis.opendocument.text':
						$subType = 'application/msword';
						break;

					//Powerpoints
					case 'application/vnd.google-apps.presentation':
					case'application/vnd.ms-powerpoint':
					case'application/vnd.ms-powerpoint.presentation.macroEnabled.12':
					case'application/vnd.oasis.opendocument.presentation':
					case'application/vnd.openxmlformats-officedocument.presentationml.slideshow':
					case'application/vnd.openxmlformats-officedocument.presentationml.presentation':
					case'application/vnd.openxmlformats-officedocument.presentationml.template':
						$subType = 'application/vnd.ms-powerpoint';
						break;

					//Excel Sheets
					case 'application/vnd.google-apps.spreadsheet':
					case'application/vnd.ms-excel':
					case'application/vnd.ms-excel.sheet.macroEnabled.12':
					case'application/vnd.oasis.opendocument.spreadsheet':
					case'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
					case'application/vnd.openxmlformats-officedocument.spreadsheetml.template':
						$subType = 'application/vnd.ms-excel';
						break;

					//Images
					case 'image/jpeg':
						$subType = 'image/jpeg';
						break;

					case 'image/png':
						$subType = 'image/png';
						break;

					//If no other form is specified default to a pdf submission form
					default:
						$subType = 'application/pdf';
						break;
				}

				//error_log(print_r($subType,true)); //final Submission type decision

				$revisionNum = $thisAss->object->revision_id;
				$attachmentName = 'v'.$revisionNum.' '.$initialName;                             

				//Grab submission content (now using Oauth)
				//$attachmentBody = file_get_contents($downloadPath);
				$attachmentBody = null;
				try {
					$oauth = new OAuth($this->getSchoologyKey(),$this->getSchoologySecret());
					$oauth->setToken($this->token['token_key'],$this->token['token_secret']);

					$oauth->fetch($downloadPath,null,OAUTH_HTTP_METHOD_GET);

					$response_info = $oauth->getLastResponseInfo();
					
					/*
					// Uncomment this section to view response headers
					
					$keys = array_keys($response_info);
					for($i = 0; $i < count($keys); $i++) {
						error_log($keys[$i]);
						error_log(print_r($response_info[$keys[$i]],true));
					}
					*/
					
					$attachmentBody = $oauth->getLastResponse();
					
				} catch(OAuthException $E) {
					error_log("Exception caught while downloading assignment attachment!\n");
					error_log("Response: ". $E->getMessage() . "\n");
					error_log($E->debugInfo . "\n");
				}

				//Schoology ID Information
				$schoologyAssId = $thisAss->object->assignment_nid;
				$schoologyUserId= $thisAss->object->uid;
				$timeStamp = current($thisAss->object->attachments->files->file)->timestamp;

				$subDate = Date('Y-m-d\TH:i:s\Z', $timeStamp);

				//error_log($subDate);

				//Query for the Salesforce Assignment record (sfid) possesing the matching Schoology Assignment ID
				$queryID = $this->storage->db->prepare("SELECT sfid FROM salesforce.ram_assignment__c WHERE (schoology_assignment_id__c = :schoologyAssId) AND (schoology_user_id__c = :schoologyUserId)");

				if($queryID->execute(array(':schoologyAssId' => $schoologyAssId , ':schoologyUserId' => $schoologyUserId))) {
					error_log('Successful Query Call ');
				} else {
					error_log('Could not perform Query call. Either you are not the correct User or you are submitting to the wrong assignment');
					throw new Exception('Could not get Assignment Submission');
				}
				//Extract the salesforce id of the obtained assignment record
				$queryRes = $queryID->fetch(PDO::FETCH_ASSOC);

				if ($queryRes == null){
				error_log("Missing sfid");
				}
				else{
				error_log('The Salesforce Assignment ID is: '.$queryRes[sfid]);
				}

				$records = array();
				$records[0] = new stdclass();
				$records[0]->Body = base64_encode($attachmentBody);
				$records[0]->Name = $attachmentName;
		        $records[0]->ParentID = $queryRes[sfid];
		        $records[0]->IsPrivate = 'false';
		        $records[0]->ContentType = $subType;

		        error_log("Creating Attachment in Salesforce. . .");
		        $upsertResponse = $mySforceConnection->create($records,'Attachment');       	
		        print_r($upsertResponse,true);

				//Set newest Submission Date and Time in the Assignment record of Salesforce
				$queryTime = $this->storage->db->prepare("UPDATE salesforce.ram_assignment__c SET submission_date_time__c  = :currTime, publish__c = FALSE, synced_to_schoology__c = TRUE WHERE (schoology_assignment_id__c = :schoologyAssId) AND (schoology_user_id__c = :schoologyUserId)");

				if($queryTime->execute(array(':currTime' => $subDate, ':schoologyAssId' => $schoologyAssId , ':schoologyUserId' => $schoologyUserId))) {
					error_log('Successful Query Call ');
				} else {
					error_log('Could not perform Query call.');
					throw new Exception('Could not add timestamp to Assignment Submission');
				}
	        } while(next($thisAss->object->attachments->files->file));
		}

		
		/**
		 * Creates a Schoology Grade from a grades Salesforce Assignment
		 *
		 * @param JSON   $thisAss Salesforce Assignment 
		 * 
		 * @throws "Invalid Assignment Data" If $thisAss is invalid
		 * @throws "Exception When Making API Call" If API call is unsuccessful
		 * @author Edgar Lopez <elopez@broadcenter.org>
		 * @return
		 */ 
		public function gradeAssignment($thisAss) {
			if(!$thisAss) {
				error_log('Error! Invalid data for grading assignment');
				error_log(print_r($thisAss,true));
				throw new Exception('Invalid Grading data');
			}

			// Course Section ID for TBR 2017-2019
			// Need to update this to query from DB instead
			$schoologyCourseSectionID = '1104817298';

			//RSET Call for Enrollement ID
			try {
				$api_enrollment_result = $this->schoology->api('sections/'.$schoologyCourseSectionID.'/enrollments', 'GET');
			//	error_log(print_r($api_enrollment_result,true));
			} catch(Exception $e) {
				error_log('Exception when making API call');
				error_log($e->getMessage());
			}
			$enrollementID = $api_enrollment_result->result->enrollment[1]->id;
			//error_log(print_r($enrollementID,true));
			
			//This rest call will give a list of all the users enrolled to a specified Course Section in the form of an array.
			//In initilization functions use UID to match to the salesforce record's schoology_user_id__c and place appropiate enrollement ID's 	

			//Ideal Schology Grade Object members and coressponding salesforce object fields Once filed in
			/*  //Use this Method once all of the fields are included into the Assignment Records	
			$gradeOptions = array(
				"grades"=>array(
					"grade"=>array(	
							"type"=>"assignment",
							"enrollment_id" =>$thisAss->data->schoology_enrollment_id__c,	
							"assignment_id" =>$thisAss->data->schoology_assignment_id__c,
							"grade" => $thisAss->data->score_formula__c,
							"comment" => $commentString,
							"comment_status"=>"1"
					)
				)
			);
			*/	 

			$gradeOptions = array(
				"grades"=>array(
					"grade"=>array(	
							"type"=>"assignment",
							"enrollment_id" =>$enrollementID,	
							"assignment_id" =>$thisAss->data->schoology_assignment_id__c,
							"grade" => ($thisAss->data->Grade_Type__c == 'Graded' ? $thisAss->data->score_formula__c : $thisAss->data->Completed__c),
							"comment" => $thisAss->data->Grader_Comments__c,
							"comment_status"=>"1"
					)
				)
			);

			//were the correct values obtained?
			//	error_log($gradeOptions["grades"]["grade"]["enrollment_id"]);
			//	error_log($gradeOptions["grades"]["grade"]["assignment_id"]);
			//	error_log($gradeOptions["grades"]["grade"]["grade"]);

			
			//Insert grade into schoology Grade object 
			try {
				$api_result = $this->schoology->api('/sections/'.$schoologyCourseSectionID.'/grades', 'PUT', $gradeOptions);
			//		error_log(print_r($api_result,true));
			} catch(Exception $e) {
				error_log('Exception when making API call');
				error_log($e->getMessage());
			}
				// successful call result
			if($api_result != null && in_array($api_result->http_code, $this->httpSuccessCodes)) {
				$query = $this->storage->db->prepare("UPDATE salesforce.ram_assignment__c SET synced_to_schoology__c = TRUE, publish__c = FALSE WHERE sfid = :sfid");
				if($query->execute(array(':sfid' => $thisAss->data->sfid))) {
					error_log('Success! Updated Assignment with ID: ' . $api_result->result->id);
					return true;
				} else {
					error_log('Could not update Assignment ' . $thisAss->data->schoology_assignment_id__c);
					throw new Exception('Could not update Assignment');
				}
			}				
		}

		//Required: Larger Function iterating through all the Courses in Schoology

		/**
		 * Synchronizes a Schoology Course and a Salesforce Cohort
		 *
		 * @param JSON   $thisCohort Salesforce Assignment 
		 * @param String $schoologyCourseID
		 *
		 * @throws "Invalid Cohort Data" If $thisCohort is invalid
		 * @throws "Exception When Making API Call" If API call is unsuccessful
		 * @author Edgar Lopez <elopez@broadcenter.org>
		 * @return
		 */ 
		public function syncCohort($schoologyCourseID){

			//Rest call to Courses
			try {
				$api_courses_result = $this->schoology->api('/sections/1104817298/enrollments?start=0&limit=100', 'GET');
				//error_log(print_r($api_courses_result,true));
			} catch(Exception $e) {
				error_log('Exception when making syncAPI call');
				error_log($e->getMessage());
			}

			reset($api_courses_result->result->enrollment);
			$residentCount = 0;
			do{
				error_log($api_courses_result->result->enrollment[$residentCount]->name_display);
				error_log('Schoology id:'.$api_courses_result->result->enrollment[$residentCount]->uid);
				error_log('Enrollment id:'.$api_courses_result->result->enrollment[$residentCount]->id);
				++$residentCount;
			}
			while ($residentCount <80);

			/*
			//Course ID, Course Section ID, Course Code, Description Synced to Schoology(missing)	

			//Rest call to Course
			try {
				$api_course_result = $this->schoology->api('/courses/'.$schoologyCourseID , 'GET');
			//	error_log(print_r($api_course_result,true));
			} catch(Exception $e) {
				error_log('Exception when making syncAPI call');
				error_log($e->getMessage());
			}

			$schoologyCourseCode = $api_course_result->result->course_code;
			$courseTitle = $api_course_result->result->title;
			$description = $api_course_result->result->description;

			//error_log($schoologyCourseCode);
			
			//Rest call to Course Section to get the Course Section ID,
			//Array Indexing used because of the assumption that only one course section will exsit
			try {
				$api_section_result = $this->schoology->api('/courses/'.$schoologyCourseID.'/sections' , 'GET');
			//	error_log(print_r($api_section_result,true));
			} catch(Exception $e) {
				error_log('Exception when making syncAPI call');
				error_log($e->getMessage());
			}
			$schoologyCourseSectionID = $api_section_result->result->section[0]->id;

			error_log($schoologyCourseSectionID);

			//Given a schoology Course
			//Find Cohorts in Salesforce with either matching titles or course_id's
			//If a Match exsits, then it will be updated to match schoology's info if not it will be created.
			//what about duplicate cohorts on salesforce via misspelled names or repeated names?
			$queryCourse = $this->storage->db->prepare("SELECT schoology_id__c, schoology_course_section_id__c, schoology_course_code__c, name, description__c, synced_to_schoology__c FROM salesforce.ram_cohort__c WHERE (schoology_id__c = :tableschoologycourseid) OR (name = :coursetitle)");

			if($queryCourse->execute(array(':tableschoologycourseid' => $schoologyCourseID, ':coursetitle' => $courseTitle))) {
				error_log('Successful Course Query Call ');
			} else {
				error_log('Could not perform Query call.');
				throw new Exception('Could not add timestamp to Assignment Submission');
			}

				//Extract the salesforce id of the obtained assignment record
			$queryRes = $queryCourse->fetch(PDO::FETCH_ASSOC);
			error_log('The Cohort Data is: '.$queryRes);

			if ($queryRes == null){
				error_log("Missing Cohort, One Sec...");
				$queryAddCourse = $this->storage->db->prepare("INSERT INTO salesforce.ram_cohort__c (schoology_id__c, schoology_course_section_id__c, schoology_course_code__c, name, description__c) VALUES (:schoology_course_id, :schoology_course_section_id, :schoology_course_code, :name, :description)");

				if($queryAddCourse->execute(array(':schoology_course_id' => $schoologyCourseID, ':schoology_course_section_id' => $schoologyCourseSectionID, ':schoology_course_code' => $schoologyCourseCode, ':name' => $courseTitle, ':description' => $description))){ 
					error_log('Successful Course Query Call '); //missing sync to schoology
				} else {
				error_log('Could not perform Query call.');
				throw new Exception('Could not add Cohort to Salesforce');
				}
			}

			else{ //check to see if the fields are matching
			error_log('The Cohort Data is: '.$queryRes);
			error_log('1-'.$queryRes["schoology_course_section_id__c"]);
			error_log('2-'.$queryRes[schoology_id__c]);


			}

			
			//Get the specified variables
			//compare to the fields in Salesforce if not matching set appropiatley	
			if($description != $thisAss->data->description__c){
				$thisAss->data->description__c = $description;
			}

			if($schoologyCourseID != $thisAss->data->schoology_id__c){
				$thisAss->data->description__c = $description;
			}

			if($schoologyCourseSectionID != $thisAss->data->schoology_course_section_id__c){
				$thisAss->data->description__c = $description;
			}

			//Assumptions 1. The Cohort Object already exsits
			//			  2. Schoology Information has priority over Salesforce
		
			$thisAss->data->synced_to_schoology__c ='1';
			*/
		}
		/**
		 * Creates a Schoology Grade from a grades Salesforce Assignment
		 *
		 * @param JSON   $thisAss Salesforce Assignment 
		 * 
		 * @throws "Invalid Assignment Data" If $thisAss is invalid
		 * @throws "Exception When Making API Call" If API call is unsuccessful
		 * @author Edgar Lopez <elopez@broadcenter.org>
		 * @return
		 */ 
		public function syncProgram($thisAss,$schoologyCourseSectionID){
			//Schoology Cohort 
			//Schoology Enrollment ID
			//Schology User ID

		//Use CourseSection to do REST Enrollment call to get a list. Mathch the enrolled students by 



		}

		/**
		 * Creates a Schoology Grade from a grades Salesforce Assignment
		 *
		 * @param JSON   $thisAss Salesforce Assignment 
		 * 
		 * @throws "Invalid Assignment Data" If $thisAss is invalid
		 * @throws "Exception When Making API Call" If API call is unsuccessful
		 * @author Edgar Lopez <elopez@broadcenter.org>
		 * @return
		 */ 
		public function syncAssignmentMaster($thisAss,$schoologyCourseSectionID){
			//Title
			//Due Date
			//Description
			//Grade Type -> Scale/Rubric
			//Schoology ID
			//Synced to Schoology


		}

		/**
		 * Creates a Schoology Grade from a grades Salesforce Assignment
		 *
		 * @param JSON   $thisAss Salesforce Assignment 
		 * 
		 * @throws "Invalid Assignment Data" If $thisAss is invalid
		 * @throws "Exception When Making API Call" If API call is unsuccessful
		 * @author Edgar Lopez <elopez@broadcenter.org>
		 * @return
		 */ 
		public function syncAssignment($thisAss,$schoologyCourseSectionID){
			//Grader Comments
			//Score
			//Submission Date
			//Synced to Schoology
			//User Program
		}

	}
?>