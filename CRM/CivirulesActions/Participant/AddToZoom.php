<?php

use Firebase\JWT\JWT;
use Zttp\Zttp;

class CRM_CivirulesActions_Participant_AddToZoom extends CRM_Civirules_Action{

	/**
	 * Method processAction to execute the action
	 *
	 * @param CRM_Civirules_TriggerData_TriggerData $triggerData
	 * @access public
	 *
	 */
	public function processAction(CRM_Civirules_TriggerData_TriggerData $triggerData) {
	  $contactId = $triggerData->getContactId();
	  $event = $triggerData->getEntityData('Event');
	  $webinar = $this->getWebinarID($event['id']);
	  $participant = $this->getContactData($contactId);
	  $meeting = $this->getMeetingID($event['id']);
	  if(!empty($meeting)){
	  	$this->addParticipant($participant, $meeting, $triggerData, 'Meeting');
	  } elseif (!empty($webinar)) {
	  	$this->addParticipant($participant, $webinar, $triggerData, 'Webminar');
	  }
	}

	/**
	 * Get an event's webinar id
	 * @param  int $event The event's id
	 * @return string The event's webinar id
	 */
	private function getWebinarID($event) {
		$result;
		$customField = CRM_NcnCiviZoom_Utils::getWebinarCustomField();
		try {
			$apiResult = civicrm_api3('Event', 'get', [
			  'sequential' => 1,
			  'return' => [$customField],
			  'id' => $event,
			]);
			// Remove any empty spaces
			$result = trim($apiResult['values'][0][$customField]);
			$result = str_replace(' ', '', $result);
		} catch (Exception $e) {
			throw $e;
		}

		return $result;
	}

	/**
	 * Get an event's Meeting id
	 * @param  int $event The event's id
	 * @return string The event's Meeting id
	 */
	private function getMeetingID($event) {
		$result;
		$customField = CRM_NcnCiviZoom_Utils::getMeetingCustomField();
		try {
			$apiResult = civicrm_api3('Event', 'get', [
			  'sequential' => 1,
			  'return' => [$customField],
			  'id' => $event,
			]);
			// Remove any empty spaces
			$result = trim($apiResult['values'][0][$customField]);
			$result = str_replace(' ', '', $result);
		} catch (Exception $e) {
			throw $e;
		}

		return $result;
	}

	/**
	 * Get given contact's email, first_name, last_name,
	 * city, state/province, country, post code
	 *
	 * @param int $id An existing CiviCRM contact id
	 *
	 * @return array Retrieved contact info
	 */
	private function getContactData($id) {
		$result = [];

		try {
			$result = civicrm_api3('Contact', 'get', [
			  'sequential' => 1,
			  'return' => ["email", "first_name", "last_name", "street_address", "city", "state_province_name", "country", "postal_code"],
			  'id' => $id,
			])['values'][0];
		} catch (Exception $e) {
			watchdog(
			  'NCN-Civi-Zoom CiviRules Action (AddToZoom)',
			  'Something went wrong with getting contact data.',
			  array(),
			  WATCHDOG_INFO
			);
		}

		return $result;
	}

	/**
	 * Add's the given participant data as a single participant
	 * to a Zoom Webinar/Meeting with the given id.
	 *
	 * @param array $participant participant data where email, first_name, and last_name are required
	 * @param int $entityID id of an existing Zoom webinar/meeting
	 * @param string $entity 'Meeting' or 'Webminar'
	 */
	private function addParticipant($participant, $entityID, $triggerData, $entity) {
		$event = $triggerData->getEntityData('Event');
		$accountId = CRM_NcnCiviZoom_Utils::getZoomAccountIdByEventId($event['id']);
		$settings = CRM_NcnCiviZoom_Utils::getZoomSettings();
		if($entity == 'Webminar'){
			$url = $settings['base_url'] . "/webinars/$entityID/registrants";
		} elseif($entity == 'Meeting'){
			$url = $settings['base_url'] . "/meetings/$entityID/registrants";
		}
		$token = $this->createJWTToken($accountId);
		$response = Zttp::withHeaders([
			'Content-Type' => 'application/json;charset=UTF-8',
			'Authorization' => "Bearer $token"
		])->post($url, $participant);

		// Alert to user on success.
		if ($response->isOk()) {
			$firstName = $participant['first_name'];
			$lastName = $participant['last_name'];
			$msg = 'Participant Added to Zoom. $entity ID: '.$entityID;
			$this->logAction($msg, $triggerData, \PSR\Log\LogLevel::INFO);

			CRM_Core_Session::setStatus(
				"$firstName $lastName was added to Zoom $entity $entityID.",
				ts('Participant added!'),
				'success'
			);
		} else {
			$result = $response->json();
			$msg = $result['message'].' $entity ID: '.$entityID;
			$this->logAction($msg, $triggerData, \PSR\Log\LogLevel::ALERT);
		}
	}

	private function createJWTToken($id) {
		$settings = CRM_NcnCiviZoom_Utils::getZoomSettings($id);
		$key = $settings['secret_key'];
		$payload = array(
		    "iss" => $settings['api_key'],
		    "exp" => strtotime('+1 hour')
		);
		$jwt = JWT::encode($payload, $key);
		return $jwt;
	}

	public function getJoinUrl($object){
		$eventId = $object->event_id;
		$accountId = CRM_NcnCiviZoom_Utils::getZoomAccountIdByEventId($eventId);
		$settings = CRM_NcnCiviZoom_Utils::getZoomSettings();
		$webinar = $object->getWebinarID($eventId);
		$meeting = $object->getMeetingID($eventId);
		$url = '';
		$eventType = '';
	  if(!empty($meeting)){
	  	$url = $settings['base_url'] . "/meetings/".$meeting;
	  	$eventType = 'Meeting';
	  } elseif (!empty($webinar)) {
	  	$url = $settings['base_url'] . "/webinars/".$webinar;
	  	$eventType = 'Webinar';
	  } else {
	  	return [null, null, null];
	  }
	  $token = $object->createJWTToken($accountId);
		$response = Zttp::withHeaders([
			'Content-Type' => 'application/json;charset=UTF-8',
			'Authorization' => "Bearer $token"
		])->get($url);
		$result = $response->json();
		$joinUrl = $result['join_url'];
		$password = isset($result['password'])? $result['password'] : '';
		return [$joinUrl, $password, $eventType];
	}

	public static function checkEventWithZoom($params){
		if(empty($params) || empty($params["account_id"])
			|| empty($params["entityID"])
			|| empty($params["entity"])){
			return ['status' => null , 'message' => "Parameters missing"];
		}

		$object = new CRM_CivirulesActions_Participant_AddToZoom;
		$url = '';
		$settings = CRM_NcnCiviZoom_Utils::getZoomSettings($params["account_id"]);
		if($params["entity"] == 'Meeting'){
	  	$url = $settings['base_url'] . "/meetings/".$params["entityID"];
		} elseif ($params["entity"] == 'Webinar') {
	  	$url = $settings['base_url'] . "/webinars/".$params["entityID"];
		}

	  $token = $object->createJWTToken($params["account_id"]);
		$response = Zttp::withHeaders([
			'Content-Type' => 'application/json;charset=UTF-8',
			'Authorization' => "Bearer $token"
		])->get($url);
		$result = $response->json();
		// CRM_Core_Error::debug_var('response', $result);
		if($response->isOk()){
			if(!empty($result['registration_url'])){
				return ["status" => 1, "message" => $params["entity"]." has been verified"];
			}else{
				return ["status" => 0, "message" => "Please enable the Registration as required for the Zoom ".$params["entity"].": ".$params["entityID"]];
			}
		} else {
			return ["status" => 0, "message" => $params["entity"]." does not belong to the ".$settings['name']];
		}
	}

	/**
	 * Method to return the url for additional form processing for action
	 * and return false if none is needed
	 *
	 * @param int $ruleActionId
	 * @return bool
	 * @access public
	 */
	public function getExtraDataInputUrl($ruleActionId) {
  		return FALSE;
	}

}