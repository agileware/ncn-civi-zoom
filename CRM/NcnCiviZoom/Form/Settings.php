<?php

use Firebase\JWT\JWT;
use Zttp\Zttp;

use CRM_NcnCiviZoom_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://wiki.civicrm.org/confluence/display/CRMDOC/QuickForm+Reference
 */
class CRM_NcnCiviZoom_Form_Settings extends CRM_Core_Form {

  public $_id = NULL;
  public $_act = NULL;

  public function preProcess() {
    CRM_Utils_System::setTitle(ts("Zoom Settings"));
    $this->_id = CRM_Utils_Request::retrieve('id', 'String', $this);
    $this->_act = CRM_Utils_Request::retrieve('act', 'Positive', $this);
    //setting the user context to zoom accounts list page
    $session = CRM_Core_Session::singleton();
    $session->pushUserContext(CRM_Utils_System::url('civicrm/Zoom/settings',"reset=1"));
    parent::preProcess();
  }

  public function buildQuickForm() {
    $deleteAction = FALSE;
    if ($this->_act & CRM_Core_Action::DELETE) {
      $this->addButtons(array(
        array(
          'type' => 'submit',
          'name' => E::ts("Delete"),
          'isDefault' => TRUE,
        ),
        array(
          'type' => 'cancel',
          'name' => E::ts('Cancel'),
        ),
      ));
      $deleteAccountDetails = CRM_NcnCiviZoom_Utils::getZoomAccountSettingsByIdOrName($this->_id);
      $this->zoomName = $deleteAccountDetails['name'];
      $this->assign('zoomName',$this->zoomName);
      $deleteAction = TRUE;
    }
    else {
      $this->addButtons(array(
        array(
          'type' => 'submit',
          'name' => E::ts('Save'),
          'isDefault' => TRUE,
        ),
        array(
          'type' => 'cancel',
          'name' => E::ts('Cancel'),
        ),
      ));
    }

    if($this->_act == 2 || $this->_act == 1){
      if(($this->_act == 2) && !empty($this->_id)){
        $zoomSettingsToEdit = CRM_NcnCiviZoom_Utils::getZoomAccountSettingsByIdOrName($this->_id);
        $this->zoomName = $zoomSettingsToEdit['name'];
        $this->assign('zoomName', $this->zoomName);
      } else{
        $this->zoomName = 'New';
        $this->assign('zoomName', $this->zoomName);
      }
      $this->add('text', 'name', ts('Zoom Name'), array('size' => 48,), TRUE);
      $this->add('password', 'api_key', ts('Api Key'), array(
        'size' => 48,
      ), TRUE);
      $this->add('password', 'secret_key', ts('Secret Key'), array(
        'size' => 48,
      ), TRUE);
      $testButton = array(
        'type' => 'upload',
        'name' => ts('Test Settings'),
        'subName' => 'done'
      );
      $saveButton = array(
        'type' => 'submit',
        'name' => E::ts('Save'),
        'isDefault' => TRUE
      );
      $cancelButton = array(
        'type' => 'cancel',
        'name' => E::ts('Cancel')
      );
      $buttons[] = $saveButton;
      if(($this->_act == 2)){
        $buttons[] = $testButton;
      }
      $buttons[] = $cancelButton;
      $this->addButtons($buttons);
    }

    // add form elements
    if(empty($this->_id) && empty($this->_act)){
      $this->add('text', 'base_url', ts('Base Url'), array(
        'size' => 48,
      ), TRUE);
      $this->add(
        'select',
        'custom_field_id_webinar',
        'Custom Field for Webinar',
        $this->getEventCustomFields(),
        TRUE,
        array('multiple' => FALSE)
      );
      $this->add(
        'select',
        'custom_field_id_meeting',
        'Custom Field For Meeting',
        $this->getEventCustomFields(),
        TRUE,
        array('multiple' => FALSE)
      );
      $this->add(
        'select',
        'custom_field_account_id',
        'Custom Field For Zoom Account Id',
        $this->getEventCustomFields(),
        TRUE,
        array('multiple' => FALSE)
      );

      $editAction = CRM_Core_Action::UPDATE;
      $delAction = CRM_Core_Action::DELETE;
      $addAction = CRM_Core_Action::ADD;
      $rows = CRM_NcnCiviZoom_Utils::getAllZoomAccountSettings();
      foreach ($rows as $Id => $values) {
        if(strlen($values['api_key']) > 4){
          $rows[$Id]['api_key'] = substr($values['api_key'], 0, 4).(str_repeat('*',strlen($values['api_key']) - 4));
        }
        if(strlen($values['secret_key']) > 4){
          $rows[$Id]['secret_key'] = substr($values['secret_key'], 0, 4).(str_repeat('*',strlen($values['secret_key']) - 4));
        }
        if (!$deleteAction) {
          $editURL = CRM_Utils_System::href('Edit', 'civicrm/Zoom/settings', 'reset=1&act='.$editAction.'&id='.$Id);
          $deleteURL = CRM_Utils_System::href('Delete', 'civicrm/Zoom/settings', 'reset=1&act='.$delAction.'&id='.$Id);
          $rows[$Id]['action'] = sprintf("<span>%s &nbsp;/&nbsp; %s</span>", $editURL, $deleteURL, $Id);
        }
      }
    }

    $headers = [ts('Id'), ts('Account Name'), ts('Api Key'), ts('Secret Key'), ts('Action')];
    $columnNames = array('id', 'name', 'api_key', 'secret_key', 'action');
    // export form elements
    $defaults = CRM_NcnCiviZoom_Utils::getZoomSettings($this->_id);
    $this->assign('act', $this->_act);
    $this->assign('id', $this->_id);
    $this->assign('deleteAction', $deleteAction);
    $this->assign('headers',$headers);
    if(!empty($rows)){$this->assign('rows',$rows);}
    $this->assign('columnNames',$columnNames);
    //Set default Values
    $this->setDefaults($defaults);
    parent::buildQuickForm();
  }

  /**
   * @return array
   */
  public static function getEventCustomFields() {
    $cFields = array('' => '- select -');
    $cGroupResult = civicrm_api3('CustomGroup', 'get', array(
      'sequential' => 1,
      'extends' => "Event",
      'options' => array('limit' => 0),
    ));

    if (empty($cGroupResult['values'])) {
      return $cFields;
    }

    foreach ($cGroupResult['values'] as $cgKey => $cgValue) {
      $cFieldResult = civicrm_api3('CustomField', 'get', array(
        'sequential' => 1,
        'custom_group_id' => $cgValue['id'],
        'options' => array('limit' => 0),
      ));

      if (!empty($cFieldResult['values'])) {
        foreach ($cFieldResult['values'] as $cfKey => $cfValue) {
          $cFields[$cfValue['id']] = $cfValue['label'];
        }
      }
    }

    return $cFields;
  }

  public function postProcess() {
    $buttonName = $this->controller->getButtonName();
    $values = $this->exportValues();
    if ($buttonName == $this->getButtonName('upload', 'done')) {
      $result = self::testAPIConnectionSettings($this->_id);
      $redirectUrl = CRM_Utils_System::url('civicrm/Zoom/settings', 'reset=1&act='.$this->_act."&id=".$this->_id);
    } else {
      $editAction = CRM_Core_Action::UPDATE;
      $delAction = CRM_Core_Action::DELETE;
      $addAction = CRM_Core_Action::ADD;

      $tableName = CRM_NcnCiviZoom_Constants::ZOOM_ACCOUNT_SETTINGS;
      if(($this->_act == $editAction) && !empty($this->_id)){
        //Update the existing  settings
        $api_key      = $values['api_key'];
        $secret_key   = $values['secret_key'];
        $zoom_name    = $values['name'];

        $queryParams = array(
          1 => array($zoom_name, 'String'),
          2 => array($api_key, 'String'),
          3 => array($secret_key, 'String'),
          4 => array($this->_id, 'Integer')
        );
        $query = "UPDATE {$tableName} SET name = %1, api_key = %2, secret_key = %3 WHERE id = %4";
        CRM_Core_Dao::executeQuery($query, $queryParams);

        $result['message'] = ts('Zoom account settings have been updated');
        $result['type'] = 'success';

      } elseif (($this->_act == $addAction) && empty($this->_id)) {
        // Add new zoom setting
        $zoom_name = $values['name'];
        $api_key      = $values['api_key'];
        $secret_key   = $values['secret_key'];
        $queryParams = array(
          1 => array($zoom_name, 'String'),
          2 => array($api_key, 'String'),
          3 => array($secret_key, 'String'),
        );
        $query = "INSERT INTO {$tableName} (name, api_key, secret_key) VALUES (%1, %2 , %3)";
        CRM_Core_Dao::executeQuery($query, $queryParams);
        $result['message'] = ts('Your new zoom account settings have been saved');
        $result['type'] = 'success';
      }

      //Delete the zoom setting
      if(($this->_act == $delAction) && !empty($this->_id)){
        $queryParams = array(1 => array($this->_id, 'Integer'));
        $query = "DELETE FROM {$tableName} WHERE id=%1";
        CRM_Core_Dao::executeQuery($query, $queryParams);
        $result['message'] = ts($this->zoomName.' settings has been deleted');
        $result['type'] = 'success';
      }

      if(empty($this->_act) && empty($this->_id)){
        $zoomSettings['base_url']     = $values['base_url'];
        $zoomSettings['custom_field_id_webinar'] = $values['custom_field_id_webinar'];
        $zoomSettings['custom_field_id_meeting'] = $values['custom_field_id_meeting'];
        $zoomSettings['custom_field_account_id'] = $values['custom_field_account_id'];
        CRM_Core_BAO_Setting::setItem($zoomSettings, ZOOM_SETTINGS, 'zoom_settings');
        $result['message'] = ts('Your Settings have been saved');
        $result['type'] = 'success';
      }
      $redirectUrl    = CRM_Utils_System::url('civicrm/Zoom/settings', 'reset=1');
    }

    CRM_Core_Session::setStatus($result['message'], ts('Zoom Settings'), $result['type']);
    CRM_Utils_System::redirect($redirectUrl);
    parent::postProcess();
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = [];
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

  public static function testAPIConnectionSettings($id = null) {
    if(empty($id)){
      $result = [
        'message' => 'Parameters missing',
        'type' => 'alert'
      ];
      return $result;
    }
    $settings = CRM_NcnCiviZoom_Utils::getZoomSettings($id);

    $url = $settings['base_url'] . "/report/daily";
    $token = self::createJWTToken($id);
    $params['year'] = date('Y');
    $params['month'] = date('m');
    $response = Zttp::withHeaders([
      'Content-Type' => 'application/json;charset=UTF-8',
      'Authorization' => "Bearer $token"
    ])->get($url, $params);
    // Alert to user on success.
    if ($response->isOk()) {
      $msg = "Connection settings are correct.";
      $type = 'success';
    } else {
      $result = $response->json();
      $msg = $result['message'];
      $type = 'alert';
    }

    $status['message'] = $msg;
    $status['type'] = $type;
    return $status;
  }

  public static function createJWTToken($id = null) {
    if(empty($id)){
      return null;
    }
    $settings = CRM_NcnCiviZoom_Utils::getZoomSettings($id);
    $key = $settings['secret_key'];
    $payload = array(
        "iss" => $settings['api_key'],
        "exp" => strtotime('+1 hour')
    );
    $jwt = JWT::encode($payload, $key);

    return $jwt;
  }

}
