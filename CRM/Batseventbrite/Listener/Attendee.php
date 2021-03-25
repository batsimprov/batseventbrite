<?php

const CLASS_NAME_FIELD = 'custom_3';
const CLASS_CODE_FIELD = 'custom_4';

const STUDENT_ROLE = 7;
const CODE_FIELD = 'custom_157';
const CREDIT_AMOUNT_FIELD = 'custom_161';
const PERCENT_OFF_FIELD = 'custom_162';
const EVENTBRITE_DISCOUNT_ID_FIELD = 'custom_158';
const EB_DISCOUNT_STATUS_FIELD = 'custom_163';
const USED_IN_EVENTBRITE = 3;
const VALUE_USED_FIELD = 'custom_164';
const USED_ON_DATE_FIELD = 'custom_175';

const EVENTBRITE_EVENT_ID_FIELD = 'custom_167';
const EVENTBRITE_ORDER_ID_FIELD = 'custom_168';
const APPLIED_TO_CLASS_NAME_FIELD = 'custom_169';
const APPLIED_TO_CLASS_CODE_FIELD = 'custom_170';

class CRM_Batseventbrite_Listener_Attendee {
  public function processPromoCode($processor) {
    \CRM_Core_Error::debug_log_message("in processPromoCode");
    $promoCode = $processor->attendee['promotional_code'];

    if (($promoCode['percent_off'] == 0) and ($promoCode['amount_off']['major_value'] == 0)) {
      // ignore
      return;
    }

    $eb = CRM_Eventbrite_EventbriteApi::singleton();

    $discountCode = $promoCode['code'];
    $processor->discountId = $promoCode['id'];
    $processor->voucherCode = $discountCode;
    $discountDescription = "Eventbrite Discount $discountCode";

    $ticketClassId = $processor->attendee['ticket_class_id'];
    $path = "events/{$processor->attendee['event_id']}/ticket_classes/$ticketClassId";
    $processor->ticketClass = $eb->request($path);

    $processor->ticketPrice = $processor->ticketClass['cost']['major_value'];
    $paidPrice = $processor->attendee['costs']['gross']['major_value'];
    $processor->valueUsed = $processor->ticketPrice - $paidPrice;

    // TODO validate that voucher type corresponds to amount vs. percent off
    if (array_key_exists('amount_off', $promoCode) and strlen($discountCode) == 6) {
      $processor->isCreditVoucher = true;
      $discountDescription .= " ({$promoCode['amount_off']['display']})";
      $amountOff = $promoCode['amount_off']['major_value'];
      $percentOff = NULL;
    } else if (array_key_exists('amount_off', $promoCode)) {
      $processor->isCreditVoucher = false;
      $discountDescription .= " ({$promoCode['amount_off']['display']})";
      $amountOff = $promoCode['amount_off']['major_value'];
      $percentOff = NULL;
    } else {
      $processor->isCreditVoucher = false;
      $discountDescription .= " ({$promoCode['percent_off']}%)";
      // percent off
      $amountOff = NULL;
      $percentOff = $promoCode['percent_off'];

      if (substr(CRM_Utils_Date::processDate(CRM_Utils_Array::value('created', $processor->attendee)), 0, 4) < "2021") {
        return;
      }
    }

    $classNameOptions = civicrm_api3('Event', 'getoptions', [
      'field' => "custom_3",
    ])['values'];
    $className = $classNameOptions[$processor->event[CLASS_NAME_FIELD]];

    $discountCodeParams = array(
      EB_DISCOUNT_STATUS_FIELD => USED_IN_EVENTBRITE,
      VALUE_USED_FIELD => $processor->valueUsed,
      USED_ON_DATE_FIELD => CRM_Utils_Date::processDate(CRM_Utils_Array::value('created', $processor->attendee)),
      EVENTBRITE_EVENT_ID_FIELD => $processor->eventId,
      EVENTBRITE_ORDER_ID_FIELD => $processor->entityId,
      APPLIED_TO_CLASS_NAME_FIELD => $className,
      APPLIED_TO_CLASS_CODE_FIELD => $processor->event[CLASS_CODE_FIELD],
      'status_id' => 'Completed',
    );

    $result = _eventbrite_civicrmapi('Activity', 'get', array(
      'activity_type_id' => 89,
      EVENTBRITE_DISCOUNT_ID_FIELD => $promoCode['id']
    ));
    if ($result['count'] == 0) {
      // eventually all discounts should be in place first and processor should throw an error...
      // create new discount code activity
      $discountCodeParams['activity_type_id'] = 89;
      $discountCodeParams[CODE_FIELD] = $discountCode;
      $discountCodeParams['source_contact_id'] = 2;
      $discountCodeParams['target_id'] = $processor->contactId;
      $discountCodeParams['subject'] = $discountDescription;
      $discountCodeParams[CREDIT_AMOUNT_FIELD] = $amountOff;
      $discountCodeParams[PERCENT_OFF_FIELD] = $percentOff;
      $discountCodeParams[EVENTBRITE_DISCOUNT_ID_FIELD] = $promoCode['id'];
    } else {
      foreach ($result['values'] as $id=>$info) {
        $discountCodeParams['id'] = $info['id'];
        break;
      }
    }

    if ($processor->isCreditVoucher and $valueUsed < $discountCodeParams[CREDIT_AMOUNT_FIELD]) {
      // make a new voucher with the balance remaining
      $newRemainderVoucherParams = [
        'activity_type_id' => 89,
        'target_id' => $processor->contactId,
        CREDIT_AMOUNT_FIELD => ($discountCodeParams[CREDIT_AMOUNT_FIELD] - $valueUsed), 
        ORIGIN_ID_FIELD => $discountCodeParams[ORIGIN_ID_FIELD],
        ORIGINAL_VALUE_FIELD => $discountCodeParams[ORIGINAL_VALUE_FIELD],
        DISCOUNT_REASON_FIELD => $discountCodeParams[DISCOUNT_REASON_FIELD],
      ];
      $remainder = _eventbrite_civicrmapi('Activity', 'create', $newRemainderVoucherParams);
    }

    \CRM_Core_Error::debug_log_message("about to creat or update activity...\n");
    \CRM_Core_Error::debug_var("discountCodeParams", $discountCodeParams);

    $result = _eventbrite_civicrmapi('Activity', 'create', $discountCodeParams);
  }

  public function handleDataLoaded($symfonyEvent) {
    \CRM_Core_Error::debug_log_message("in handleProcessCurrentAttendeeFees");
    $processor = $symfonyEvent->getSubject();

    if (!$processor instanceof \CRM_Eventbrite_WebhookProcessor_Attendee) {
      return;
    }

    self::processPromoCode($processor);
  }

  public function handleTicketTypeRoleAssigned($symfonyEvent) {
    \CRM_Core_Error::debug_log_message("in handleProcessCurrentAttendeeFees");
    $processor = $symfonyEvent->getSubject();
    // student role always for EB events
    $processor->currentRoleId = STUDENT_ROLE;
  }

  public function handleAttendeeProfileAssigned($symfonyEvent) {
    \CRM_Core_Error::debug_log_message("in handleAttendeeProfileAssigned");
    $processor = $symfonyEvent->getSubject();
    // don't store addresses we don't need them
    unset($processor->attendeeProfile['address']);
  }

  public function handleParticipantParamsAssigned($symfonyEvent) {
    \CRM_Core_Error::debug_log_message("in handleParticipantParamsAssigned");
    $processor = $symfonyEvent->getSubject();
    if ($processor->isCreditVoucher) {
      $processor->participantParams['participant_fee_level'] = $processor->ticketClass['cost']['display'];
      $processor->participantParams['participant_fee_amount'] = $processor->ticketClass['cost']['major_value'];
    }
  }
}
