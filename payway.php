<?php

require_once 'payway.civix.php';

/**
 * Implementation of hook_civicrm_config
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function payway_civicrm_config(&$config) {
  _payway_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_install
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function payway_civicrm_install() {
  // Create required tables for Stripe.
  return _payway_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_enable
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function payway_civicrm_enable() {
  return _payway_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function payway_civicrm_managed(&$entities) {
  $entities[] = array(
    'module' => 'com.osseed.payway',
    'name' => 'PayWay',
    'entity' => 'PaymentProcessorType',
    'params' => array(
      'version' => 3,
      'name' => 'PayWay',
      'title' => 'PayWay',
      'description' => 'PayWay Payment Processor',
      'class_name' => 'Payment_PayWay',
      'billing_mode' => 'form',
      'signature_label'=> 'PayWay Merchant ID',
      'user_name_label' => 'PayWay Username',
      'password_label' => 'PayWay Password',
      'is_recur' => 0,
      'payment_type' => 1
    ),
  );
  return;
}
