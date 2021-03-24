<?php

require_once 'batseventbrite.civix.php';
// phpcs:disable
use CRM_Batseventbrite_ExtensionUtil as E;
// phpcs:enable

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function batseventbrite_civicrm_config(&$config) {
  _batseventbrite_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_xmlMenu
 */
function batseventbrite_civicrm_xmlMenu(&$files) {
  _batseventbrite_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function batseventbrite_civicrm_install() {
  _batseventbrite_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
 */
function batseventbrite_civicrm_postInstall() {
  _batseventbrite_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function batseventbrite_civicrm_uninstall() {
  _batseventbrite_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function batseventbrite_civicrm_enable() {
  _batseventbrite_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
 */
function batseventbrite_civicrm_disable() {
  _batseventbrite_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_upgrade
 */
function batseventbrite_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _batseventbrite_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function batseventbrite_civicrm_managed(&$entities) {
  _batseventbrite_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_caseTypes
 */
function batseventbrite_civicrm_caseTypes(&$caseTypes) {
  _batseventbrite_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
 */
function batseventbrite_civicrm_angularModules(&$angularModules) {
  _batseventbrite_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function batseventbrite_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _batseventbrite_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function batseventbrite_civicrm_entityTypes(&$entityTypes) {
  _batseventbrite_civix_civicrm_entityTypes($entityTypes);
}

/**
 * Implements hook_civicrm_thems().
 */
function batseventbrite_civicrm_themes(&$themes) {
  _batseventbrite_civix_civicrm_themes($themes);
}


// implement eventbrite package hooks
function add_listener($dispatcher, $subjectType, $eventName) {
  $qualEventName = "eventbrite.processor.$eventName";
  $qualFunctionName = "\CRM_Batseventbrite_Listener_$subjectType::handle$eventName";
  \CRM_Core_Error::debug_log_message("adding listener for $qualEventName, handler is $qualFunctionName");
  $dispatcher->addMethodCall('addListener', array($qualEventName, $qualFunctionName));
}

function batseventbrite_civicrm_container($container) {
  \CRM_Core_Error::debug_log_message("in batsveventbrite container");
  $container->addResource(new \Symfony\Component\Config\Resource\FileResource(__FILE__));

  $dispatcher = $container->findDefinition('dispatcher');

  // events
  add_listener($dispatcher, "Event", "EventParamsSet");
  add_listener($dispatcher, "Event", "FindExistingCiviEvent");
  add_listener($dispatcher, "Event", "NewCiviEventCreated");
  add_listener($dispatcher, "Event", "BeforeUpdateExistingCiviEvent");
  add_listener($dispatcher, "Event", "AfterUpdateExistingCiviEvent");

  add_listener($dispatcher, "Order", "OrderAttendeesListSet");
  add_listener($dispatcher, "Order", "FeesSetup");
  add_listener($dispatcher, "Order", "ProcessCurrentAttendeeFees");
  add_listener($dispatcher, "Order", "ContributionParamsAssigned");
  add_listener($dispatcher, "Order", "PaymentParamsAssigned");

  add_listener($dispatcher, "Attendee", "DataLoaded");
  add_listener($dispatcher, "Attendee", "TicketTypeRoleAssigned");
  add_listener($dispatcher, "Attendee", "AttendeeProfileAssigned");
  add_listener($dispatcher, "Attendee", "ParticipantParamsAssigned");
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 */
//function batseventbrite_civicrm_preProcess($formName, &$form) {
//
//}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
//function batseventbrite_civicrm_navigationMenu(&$menu) {
//  _batseventbrite_civix_insert_navigation_menu($menu, 'Mailings', array(
//    'label' => E::ts('New subliminal message'),
//    'name' => 'mailing_subliminal_message',
//    'url' => 'civicrm/mailing/subliminal',
//    'permission' => 'access CiviMail',
//    'operator' => 'OR',
//    'separator' => 0,
//  ));
//  _batseventbrite_civix_navigationMenu($menu);
//}
