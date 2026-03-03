<?php

/**
 * Payment Processor class for PayWay
 */
class CRM_Core_Payment_PayWay extends CRM_Core_Payment {

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = null;

  /**
   * mode of operation: live or test
   *
   * @var object
   * @static
   */
  static protected $_mode = null;
   
  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    $this::$_mode             = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName    = ts('PayWay');
  }

  /**
   * Singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return object
   * @static
   *
   */
  static function &singleton($mode, &$paymentProcessor, &$paymentForm = NULL, $force = FALSE) {
      $processorName = $paymentProcessor['name'];
      if (self::$_singleton[$processorName] === NULL ) {
          self::$_singleton[$processorName] = new self($mode, $paymentProcessor);
      }
      return self::$_singleton[$processorName];
  }

  /**
   * This function checks to see if we have the right config values
   *
   * @return string the error message if any
   * @public
   */
  function checkConfig() {
    $config = CRM_Core_Config::singleton();
    $error = array();

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('The "Username" is not set in the PayWay Payment Processor settings.');
    }

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('The "Password" is not set in the PayWay Payment Processor settings.');
    }

    if (empty($this->_paymentProcessor['signature'])) {
      $error[] = ts('The "MerchantId" is not set in the PayWay Payment Processor settings.');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
   * Submit a payment using PayWay's PHP API:
   */
  function doDirectPayment(&$params) {
    // Let a $0 transaction pass.
    if (empty($params['amount']) || $params['amount'] == 0) {
      return $params;
    }
    // Stripe amount required in cents.
    $amount = number_format($params['amount'], 2, '.', '');
    $amount = (int) preg_replace('/[^\d]/', '', strval($amount));
    $requestParameters = array();

    $requestParameters['customer.customerReferenceNumber'] = $params['qfKey'];
    $requestParameters['customer.orderNumber'] = substr($params['invoiceID'],0,10);
    $requestParameters['card.PAN'] = $params['credit_card_number'];
    if (isset($params['cvv2'])) {
      $requestParameters['card.CVN'] = $params['cvv2'];
    }
    $requestParameters['card.expiryYear'] = substr($params['credit_card_exp_date']['Y'],-2);
    $requestParameters['card.expiryMonth'] = $params['credit_card_exp_date']['M'];
    $requestParameters['card.currency'] = 'AUD';
    $requestParameters['order.amount'] = $amount;

    // Submit the request to PayWay.Net.
    $response = civicrm_payway_api_request($this->_paymentProcessor, $requestParameters);

    return $params;
  }
}

/**
 * Submits an Payway API request to Payway.
 *
 * @param $payment_method
 *   The payment method instance array associated with this API request.
 */
function civicrm_payway_api_request($payment_method, $requestParameters = array()) {

  // Include PayWay library & Set API credentials.
  require_once('libraries/Qvalent_PayWayAPI.inc');

  // Get CiviCRM config.
  $config = CRM_Core_Config::singleton();
  // Initialise the PayWay API
  $init = "";
  
  $init  = 'logDirectory='. $config->configAndLogDir;
  //$ca_path = $config->extensionsDir.'com.osseed.payway/libraries/cacerts.crt';
  $ca_path = $config->extensionsDir.'com.osseed.payway/libraries/cacert.pem';
  if (!(empty($ca_path))) {
    $init .= '&caFile=' . $ca_path;
  }
  $cert_path = $config->extensionsDir.'com.osseed.payway/libraries/ccapi.pem';
  if (!(empty($cert_path))) {
    $init .= '&certificateFile=' . $cert_path;
  }

  $paywayAPI = new Qvalent_PayWayAPI();
  $paywayAPI->initialise($init);

  // Request type
  $orderECI = "SSL";
  $orderType = "capture";

  // PayWay details
  $customerUsername = $payment_method['user_name'];
  $customerPassword = $payment_method['password'];
  $customerMerchant = $payment_method['signature'];

  // PayWay parameters
//  $requestParameters = array();
  $requestParameters['order.type'] = $orderType;
  $requestParameters['customer.username'] = $customerUsername;
  $requestParameters['customer.password'] = $customerPassword;
  $requestParameters['customer.merchant'] = $customerMerchant;
  $requestParameters['order.ECI'] = $orderECI;
  
  // Build and send the request
  $request = $paywayAPI->formatRequestParameters($requestParameters);
  // Parse the response

  // watchdog() is Drupal debugging module, disable for wordpress installs
  //watchdog('payway-request', '<pre>' . print_r($request, TRUE) . '</pre>');

  $response = $paywayAPI->processCreditCard($request);
  //watchdog('payway-response', '<pre>' . print_r($response, TRUE) . '</pre>');

  $responseParameters = $paywayAPI->parseResponseParameters($response);
  $errorMessage = "";

  //watchdog('payway-responseParameters', '<pre>' . print_r($responseParameters, TRUE) . '</pre>');
  
  // process responses
  // 0: approved
  // 1: declined
  // 2: Error, typically a network error
  // 3: Error, typically misconfiguration
  $responseMessage = ts($responseParameters[ "response.text" ]);
  $responseSummary = $responseParameters[ "response.summaryCode" ];

  switch ($responseSummary) {
    case 0:
          return $responseParameters;
          break;
    case 1:
          Civi::log()->debug('Payment Error: ' . $responseMessage); // log to DB
          CRM_Core_Session::setStatus('Transaction has been declined by your financial institution. '.$responseMessage, 'PayWay', 'error'); // show user error message
          break;
    case 2:
          Civi::log()->debug('Payment Error: ' . $responseMessage); // log to DB
          CRM_Core_Session::setStatus('Transaction has failed due to a network error, please try again later. '.$responseMessage, 'PayWay', 'error'); // show user error message
          break;
    case 3:
          Civi::log()->debug('Payment Error: ' . $responseMessage); // log to DB
          CRM_Core_Session::setStatus('Transaction has failed due to a network error, please try again later. '.$responseMessage, 'PayWay', 'error'); // show user error message
          break;
    default :
          Civi::log()->debug('Payment Error: ' . $responseMessage); // log to DB
          CRM_Core_Session::setStatus('Transaction has failed. '.$responseMessage, 'PayWay', 'error');
  }

  /*
  if ($responseSummary == 0) { 
    //approved
    return $responseParameters;
  } elseif($responseSummary== 1) { 
    // declined

    // log to DB
    Civi::log()->debug('Payment Error: ' . $responseMessage);
    // show user error message
    CRM_Core_Session::setStatus('Transaction has been declined by your financial institution. '.$responseMessage, 'PayWay', 'error');
    
    // this displays a fatal error, so don't use
    // CRM_Core_Error::fatal(ts($responseParameters[ "response.text" ]));
  }elseif () {

  } else {
    // log to DB
    Civi::log()->debug('Payment Error: ' . $responseMessage);
    // show user error message
    CRM_Core_Session::setStatus('Transaction could not be processed. ' . $responseMessage, 'PayWay', 'error');
  }
  */
}
