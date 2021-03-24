<?php

use Symfony\Component\EventDispatcher\Event;

// constants
const IN_PERSON_CLASS_EVENT_TYPE = 6;
const ONLINE_CLASS_EVENT_TYPE = 15;

const CLASS_NAME_FIELD = 'custom_3';
const CLASS_CODE_FIELD = 'custom_4';

const EVENTBRITE_ID_FIELD = 'custom_5';

const IS_CLASS_CANCELLED_FIELD = 'custom_154';
const EVENTBRITE_CHANGED_FIELD = 'custom_155';
const PREFERRED_COACHING_NAME = 'custom_200';

const COACH_PARTICIPANT_ROLE = 5;
const TUITION_FINANCIAL_TYPE_ID = 7;

const ALTERNATE_CLASS_NAMES = array(
  "Studio Scene Work" => "Studio Scenework",
  "Intro" => "Shy People"
);

class CRM_Batseventbrite_Listener_Event {
  protected static $coachCache = [];
  protected static $classNameOptionValues = null;


  public function setClassNameOptionValues() {
    if (!isset(self::$classNameOptionValues)) {
      $result = civicrm_api3('OptionValue', 'get', [
        'sequential' => 1,
        'option_group_id' => "class_name_20200615184556",
      ]);
      self::$classNameOptionValues = $result['values'];
    }
  }

  public function resolveClassName($title) {
    self::setClassNameOptionValues();

    foreach(self::$classNameOptionValues as $optionValueInfo) {
      $thisLabel = $optionValueInfo['label'];
      $altLabel = CRM_Utils_Array::value($thisLabel, ALTERNATE_CLASS_NAMES);

      \CRM_Core_Error::debug_log_message("checking if $thisLabel or $altLabel are relevant");
      \CRM_Core_Error::debug_log_message("pos " . strpos($title, $thisLabel));
      if (!is_null($altLabel)) {
        \CRM_Core_Error::debug_log_message(("pos " . strpos($title, $altLabel)));
      };

      if (strpos($title, $thisLabel) !== false || (!is_null($altLabel) && strpos($title, $altLabel) !== false)) {
        return $optionValueInfo['value'];
      }
    }
  }

  public function parseClassCode($title, $summary) {
    $codeRegex = '/\((#[0-9]{2}-[0-9]{2}-[0-9]{4})\)/';
    $codeRegexMonthOnly = '/\((#[0-9]{2}-[0-9]{4})\)/';

    $codeRegexNoDashes = '/\(#([0-9]{2})([0-9]{2})([0-9]{4})\)/';

    // check title for full format ID
    preg_match($codeRegex, $title, $match);
    if (!empty($match)) {
      return $match[1];
    }

    // check summary for full format ID
    preg_match($codeRegex, $summary, $match);
    if (!empty($match)) {
      return $match[1];
    }

    // check title for old (partial) format ID
    preg_match($codeRegexMonthOnly, $title, $match);
    if (!empty($match)) {
      return $match[1];
    }

    // check title for no dash format ID
    preg_match($codeRegexNoDashes, $title, $match);
    if (!empty($match)) {
      return '#' . $match[1] . "-" . $match[2] . "-" . $match[3];
    }
  }

  /**
   * Adjust event parameters.
   */
  public function handleEventParamsSet(Event $symfonyEvent) {
    \CRM_Core_Error::debug_log_message("in handleEventParamsSet");
    $processor = $symfonyEvent->getSubject();

    $title = $processor->title;
    $isOnline = (strpos($title, "Online") !== false);
    $classCode = self::parseClassCode($title, $processor->summary);
    \CRM_Core_Error::debug_log_message("class code is $classCode");
    $className = self::resolveClassName($title);
    \CRM_Core_Error::debug_log_message("class name is $className");

    $processor->civiEventParams['is_public'] = false;
    $processor->civiEventParams['is_monetary'] = true;
    $processor->civiEventParams['financial_type_id'] = TUITION_FINANCIAL_TYPE_ID; // tuition

    $processor->civiEventParams[IS_CLASS_CANCELLED_FIELD] = (strpos($title, 'CX') !== false);
    $processor->civiEventParams[EVENTBRITE_ID_FIELD] = $processor->entityId;
    $processor->civiEventParams[EVENTBRITE_CHANGED_FIELD] = $processor->event['changed'];

    $processor->civiEventParams[CLASS_CODE_FIELD] = $classCode;
    $processor->civiEventParams[CLASS_NAME_FIELD] = $className;
    $processor->civiEventParams['event_type_id'] = $isOnline ? ONLINE_CLASS_EVENT_TYPE : IN_PERSON_CLASS_EVENT_TYPE;
    $processor->civiEventParams['is_show_location'] = !$isOnline;

    if (strpos($title, $classCode) == false) {
      // add class code to title for convenience
      $processor->civiEventParams['title'] .= " (" . $classCode . ")";
    }
  }

  /**
   * 
   */
  public function handleFindExistingCiviEvent(Event $symfonyEvent) {
    \CRM_Core_Error::debug_log_message("in handleFindExistingCiviEvent");
    $processor = $symfonyEvent->getSubject();

    // check for existing Civi event matching this Eventbrite ID
    $result = _eventbrite_civicrmapi("Event", "get", [EVENTBRITE_ID_FIELD => $processor->entityId]);
    \CRM_Core_Error::debug_var("result", $result);

    if ($result['count'] == 1) {
      $existingRecords = $result['values'];
      foreach ($existingRecords as $key=>$existing) {
        \CRM_Core_Error::debug_var("existing", $existing);
        $processor->existingEvent = $existing;
        return;
      }
    }
  }

  public function populateCoachCache() {
    if (empty(self::$coachCache)) {
      $contactsInCoachGroup = _eventbrite_civicrmapi('GroupContact', 'get', [
        'group_id' => "BATS_Coaches_14",
        'status' => 'Added'
      ]);
      foreach ($contactsInCoachGroup['values'] as $groupContact) {
        $contact = _eventbrite_civicrmapi("Contact", 'getSingle', [
          'return' => ['display_name', PREFERRED_COACHING_NAME],
          "id" => $groupContact['contact_id']
        ]);

        self::$coachCache[] = $contact;
      }
    }
  }

  public function registerEventCoaches($event) {
    \CRM_Core_Error::debug_log_message("in registerEventCoaches for event {$event['id']}");
    self::populateCoachCache();

    foreach (self::$coachCache as $contact) {
      $displayNamePresent = strpos($event['title'], $contact['display_name']) !== false;
      if (!empty($contact[PREFERRED_COACHING_NAME])) {
        $altNamePresent = strpos($event['title'], $contact[PREFERRED_COACHING_NAME]) !== false;
      } else {
        $altNamePresent = false;
      }

      if ($displayNamePresent or $altNamePresent) {
        $registerdate = date_create($event['start']['local'])->format("y-m-d h:i:s");

        $participantparams = array(
          'event_id' => $event['id'],
          'contact_id' => $contact['id'],
          'status_id' => 1,
          'role_id' => COACH_PARTICIPANT_ROLE,
          'source' => "automatically created based on eventbrite",
          'participant_register_date' => $registerdate
        );
        $response = _eventbrite_civicrmapi("participant", "create", $participantparams);
      }
    }
  }

  public function handleNewCiviEventCreated(Event $symfonyEvent) {
    \CRM_Core_Error::debug_log_message("in handleNewCiviEventCreated");
    $processor = $symfonyEvent->getSubject();
    self::registerEventCoaches($processor->newEvent);
  }

  public function updateCoachesIfMissing($event) {
    $coachCount = civicrm_api3('Participant', 'getcount', [
      'event_id' => $event['id'],
      'status_id' => 1,
      'role_id' => COACH_PARTICIPANT_ROLE,
    ]);

    \CRM_Core_Error::debug_log_message("\nin updateCoachesIfMissing - found $coachCount coaches\n");

    if ($coachCount == 0) {
      self::registerEventCoaches($event);
    }
  }

  public function handleBeforeUpdateExistingCiviEvent(Event $symfonyEvent) {
    \CRM_Core_Error::debug_log_message("in handleBeforeUpdateExistingCiviEvent");
    $processor = $symfonyEvent->getSubject();

    $existingChangedTime = $processor->existingEvent[EVENTBRITE_CHANGED_FIELD];
    if (isset($existingChangedTime)) {
      if ($existingChangedTime == $processor->event['changed']) {
        \CRM_Core_Error::debug_log_message("setting doUpdateCiviEvent to false - has not changed");
        $processor->doUpdateCiviEvent = false;
      }
    }
  }

  public function handleAfterUpdateExistingCiviEvent(Event $symfonyEvent) {
    \CRM_Core_Error::debug_log_message("in handleAfterUpdateExistingCiviEvent");
    $processor = $symfonyEvent->getSubject();
    self::updateCoachesIfMissing($processor->existingEvent);
  }
}

