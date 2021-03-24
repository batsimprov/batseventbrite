<?php

const IS_CLASS_CANCELLED_FIELD = 'custom_154';
const PAYPAL_CLEARING_ACCOUNT = 17;
const PAYPAL_FIXED = 0.30;
const PAYPAL_PERCENT = 0.022;

const EVENTBRITE_DISCOUNT_ID_FIELD = 'custom_158';
const DISCOUNT_CODE_FIELD = 'custom_157';
const DISCOUNT_REASON_FIELD = 'custom_160';

const SCHOLARSHIP_REASON = 105;

const PAYPAL_PAYMENT_PROCESSOR = 3;
const CREDIT_PAYMENT_PROCESSOR = 5;
const SCHOLARSHIP_PAYMENT_PROCESSOR = 7;

class CRM_Batseventbrite_Listener_Order {
  public function handleOrderAttendeesListSet($symfonyEvent) {
    \CRM_Core_Error::debug_log_message("in handleOrderAttendeesListSet");
    $processor = $symfonyEvent->getSubject();

    // assume all attendees are valid students
    foreach ($processor->order['attendees'] as $attendee) {
      $processor->orderAttendees[$attendee['id']] = $attendee;
    }
  }

  public function handleFeesSetup($symfonyEvent) {
    \CRM_Core_Error::debug_log_message("in handleFeesSetup");
    $processor = $symfonyEvent->getSubject();

    $processor->scholarshipSum = 0;
    $processor->creditSum = 0;
  }

  public function handleProcessCurrentAttendeeFees($symfonyEvent) {
    \CRM_Core_Error::debug_log_message("in handleProcessCurrentAttendeeFees");
    $processor = $symfonyEvent->getSubject();
    $attendee = $processor->currentAttendeeProcessor;

    if ($attendee->isCreditVoucher and $attendee->valueUsed > 0) {
      $processor->creditSum += $attendee->valueUsed;
    }

    if ($processor->feesValue == 0.00 and $grossValue > 0.0) {
      $processor->feesValue = PAYPAL_FIXED + PAYPAL_PERCENT * $grossValue;
    }

    // add credit amount to gross sum (after calculating fees)

    \CRM_Core_Error::debug_var("grossSum", $processor->grossSum);
    \CRM_Core_Error::debug_var("creditSum", $processor->creditSum);
    $processor->grossSum += $processor->creditSum;
    $processor->feesSum += $processor->feesValue;
  }

  public function handleContributionParamsAssigned($symfonyEvent) {
    \CRM_Core_Error::debug_log_message("in handleContributionParamsAssigned");
    $processor = $symfonyEvent->getSubject();
    $processor->isCheckPayment = false;
    $processor->contributionParams['payment_instrument_id'] = "PayPal";
    $processor->contributionParams['source'] .= " {$processor->event['custom_4']}";
  }

  public function handlePaymentParamsAssigned($symfonyEvent) {
    \CRM_Core_Error::debug_log_message("in handlePaymentParamsAssigned");
    $processor = $symfonyEvent->getSubject();

    \CRM_Core_Error::debug_var("existing pmt", $processor->existingPaymentsTotalValue);

    if ($processor->existingPaymentsTotalValue > 0) {
      $processor->proposedPayments = [];
      return;
    }

    $primaryPayment = $processor->proposedPayments[0];
    $primaryPayment['payment_processor_id'] = PAYPAL_PAYMENT_PROCESSOR;

    $processor->proposedPayments = array($primaryPayment);
    \CRM_Core_Error::debug_var("proposed payments", $processor->proposedPayments);

    if ($processor->creditSum > 0) {
      // look up the source/purpose of the discount in Activity:Eventbrite Discount Code
      $result = civicrm_api3('Activity', 'get', [
        'sequential' => 1,
        'activity_type_id' => "Discount Code/Credit",
        EVENTBRITE_DISCOUNT_ID_FIELD => $processor->discountId
      ]);

      if ($result['count'] == 0) {
        // try the code if the discount ID doens't work...
        $result = civicrm_api3('Activity', 'get', [
          'sequential' => 1,
          'activity_type_id' => "Discount Code/Credit",
          DISCOUNT_CODE_FIELD => $processor->voucherCode
        ]);
      }

      if ($result['count'] > 0) {
        foreach ($result['values'] as $id=>$info) {
          $discountReason = $info[DISCOUNT_REASON_FIELD];
          if ($discountReason == SCHOLARSHIP_REASON) {
            $payment_processor_id = SCHOLARSHIP_PAYMENT_PROCESSOR;
            } else {
              $payment_processor_id = CREDIT_PAYMENT_PROCESSOR;
            }
        }
      } else {
        // couldn't find a voucher.. assume it's a credit
        $payment_processor_id = CREDIT_PAYMENT_PROCESSOR;
      }

      $creditPayment = array(
        'contribution_id' => $processor->contribution['id'],
        'trxn_date' => CRM_Utils_Date::processDate(CRM_Utils_Array::value('created', $processor->order)),
        'payment_processor_id' => $payment_processor_id,
        'trxn_id' => $processor->discountId,
        'check_number' => $processor->voucherCode,
        'total_amount' => $processor->creditSum,
        'fee_amount' => 0,
        'net_amount' => $processor->creditSum,
        'is_send_contribution_notification' => 0
      );
      if ($processor->creditSum == $processor->grossSum) {
        $processor->proposedPayments = [$creditPayment];
      } else {
        $processor->proposedPayments[] = $creditPayment;
      }
    }
  }
}
