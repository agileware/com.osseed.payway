<?php

include_once "curlexecutor.php";

define("MODE","TEST");

define('BASE_URL','https://api.payway.com.au/rest/v1');


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
    // Payway amount required in cents.
    $amount = number_format($params['amount'], 2, '.', '');

    $requestParameters = array();

    $requestParameters['customer.customerReferenceNumber'] = $params['qfKey'];
    $requestParameters['customer.orderNumber'] = substr($params['invoiceID'],0,10);
    $requestParameters['card.PAN'] = $params['credit_card_number'];
    if (isset($params['cvv2'])) {
      $requestParameters['card.CVN'] = $params['cvv2'];
    }
    $requestParameters['card.expiryYear'] = substr($params['credit_card_exp_date']['Y'],-2);
    $requestParameters['card.expiryMonth'] = $params['credit_card_exp_date']['M'];
    $requestParameters['card.currency'] = 'aud';
    $requestParameters['order.amount'] = $amount;

    $requestParameters['customer.name'] = $params['billing_first_name'] . ' ' . $params['billing_last_name'];

    // Submit the request to PayWay.Net.
    $response = civicrm_payway_api_request($this->_paymentProcessor, $requestParameters);

    // determine result
    $responseStatus  = get_value( $response, 'status' ); // get payment status e.g. approved, failed  

    if ($responseStatus == 'approved') {
      // get status types
      $statuses = CRM_Contribute_BAO_Contribution::buildOptions('contribution_status_id', 'validate');
      // find matching status to return
      $params['payment_status_id'] = array_search('Completed', $statuses); // set approved in params object. needed for civi processing
    } 
    
    $params['trxn_id'] = get_value( $response, 'transactionId' ); // update params object wity paymentr reference
    $params['is_email_receipt'] = 1; 
    $params['payment_type'] = 1;  // credit card
    $params['trxn_result_code'] = get_value( $response, 'code' );
    
    // Fetch payment processor
    $paymentProcessor = civicrm_api3('PaymentProcessor', 'getsingle', [
      'id' => $params['payment_processor_id'],
    ]);
    $processor_id = get_value( $paymentProcessor, 'payment_processor_type_id' );
    if ($processor_id) {
      $params['payment_processor'] = $processor_id;
    }
    // write debug info to log
    
    //Civi::log()->debug('Payment Processor ID: ' . print_r($processor_id, true) ); // log to DB
    //Civi::log()->debug('Payment Gateway Response: ' . print_r($params[trxn_id], true) ); // log to DB
      
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

  // Get CiviCRM config.
  $config = CRM_Core_Config::singleton();

  // Request type
  $orderECI = "SSL";
  $orderType = "capture";

  // PayWay details
  $customerUsername = $payment_method['user_name'];
  $customerPassword = $payment_method['password'];
  $customerMerchant = $payment_method['signature'];
  $customerAdmin = $payment_method['description'];

  // get value for notification emails
  if (!checkValidEmail($customerAdmin)) {
    $customerAdmin = 'cfrost@fi.net.au';
  } 

  $PUBLIC_KEY  = $customerUsername; //'T13436_PUB_upmp9cstep67ij6kr8ykkgntvqr2baj8h2x28ryu38hfwhxfnf6eadcvenfc';
  $PRIVATE_KEY = $customerPassword; //'T13436_SEC_s5pnihi76cb56fummyi24w699xtv85c32z6ymuqjq4ewbtd5389bytz4c6w3';
  $MERCHANT_ID = $customerMerchant; //'TEST';

  // PayWay parameters
  // $requestParameters = array();
  $requestParameters['order.type'] = $orderType;
  $requestParameters['customer.username'] = $customerUsername;
  $requestParameters['customer.password'] = $customerPassword;
  $requestParameters['customer.merchant'] = $customerMerchant;
  $requestParameters['order.ECI'] = $orderECI;
  $requestParameters['payment_status_id'] = 0;
  
  // PayWay TOKEN parameters
  $tokenParameters = array();
  $tokenParameters['paymentMethod']   = 'creditCard';
  $tokenParameters['cardNumber']      = $requestParameters['card.PAN'];
  $tokenParameters['cardholderName']  = $requestParameters['customer.name']; //billing_first_name billing_last_name
  $tokenParameters['cvn']             = $requestParameters['card.CVN'] ;
  $tokenParameters['expiryDateMonth'] = str_pad( $requestParameters['card.expiryMonth'] , 2, '0', STR_PAD_LEFT);
  $tokenParameters['expiryDateYear']  = str_pad( $requestParameters['card.expiryYear'] , 2, '0', STR_PAD_LEFT);

  $token = getSingsleUseTokenID($tokenParameters,$PUBLIC_KEY);              //get single use token
  //echo 'token: '. $token . '<br/>';
  Civi::log()->debug('Payment Token ' . $token); // log to DB

  $paymentParams = array();
  $paymentParams['singleUseTokenId']  = $token;
  $paymentParams['customerNumber']    = $requestParameters['customer.orderNumber'];
  $paymentParams['transactionType']   = 'payment';
  $paymentParams['principalAmount']   = $requestParameters['order.amount'];
  $paymentParams['currency']          = $requestParameters['card.currency'];
  $paymentParams['orderNumber']       = $requestParameters['customer.orderNumber'];
  $paymentParams['merchantId']        = $MERCHANT_ID;//$customerMerchant;
  $paymentParams['customerIpAddress'] = $_SERVER['REMOTE_ADDR'];
  
  /*
  $post_data = array(
    "singleUseTokenId" => $token,
    "customerNumber" => "120",
    "transactionType" => "payment",
    "principalAmount" => "20.00",
    "currency" => "aud",
    "orderNumber" => "Order-13",
    "merchantId" => MERCHANT_ID
  );
  */

  $response = createSinglePayment($token, $paymentParams,$PRIVATE_KEY);
  //var_dump($response);
  //print_r($response);

  $responseParameters = get_value( $response, 'response' );
  $responseCode       = get_value( $response, 'code' ); // 201 is success  https://www.payway.com.au/docs/rest.html#http-response-codes
  $errorMessage       = "";

  //print_r($responseParameters);
  
  // process responses
  /*
  approved 	A successful transaction.
  approved* 	A successful transaction during period when it may be declined or dishonoured.
  pending 	Currently processing.
  declined 	An unsuccessful transaction. See responseCode and responseText for more.
  voided 	Originally approved, but then cancelled prior to settlement. Does not appear on your customer's statement or form part of your settlement total.
  suspended 	An unusual payment for you to review. See fraudResult for more.
  */

  $responseMessage = get_value( $responseParameters, 'responseText' ); 
  $responseSummary = get_value( $responseParameters, 'responseCode' ); 
  $responseStatus  = get_value( $responseParameters, 'status' ); 

  $url = CRM_Utils_System::url('civicrm/contribute/transact', 
    array(

      'qfKey' => $requestParameters['customer.customerReferenceNumber'],
      '_qf_Main_display' => 'true',
      'cancel' => 'true'
      )
    );

  if ($responseCode != 201) { // abort if not success
    Civi::log()->debug('Payment Gateway Response: ' . print_r($response, true) ); // log to DB
    //return paymentErrorExit(9008, "There is a payment processor configuration problem. This is usually due to invalid account information.");
    $msg = "There is a payment processor configuration problem. This is usually due to invalid account information. Error: " . $responseCode;

    generateErrorMessage($config->userFrameworkBaseURL,$msg);
    
    //echo '<h2>Error</h2>';
    //echo '<p>'. $msg . '</p>';
    //echo '<p><a href="'. $config->userFrameworkBaseURL .'">Return to homepage</a></p>';

    CRM_Core_Session::setStatus($msg, 'PayWay', 'error'); // show error 
    
    exit();
    
  } else {
    // send email to admin
    sendNotificationEmail($responseParameters,$customerAdmin);

    switch ($responseStatus) {

      case 'approved':
      
        Civi::log()->debug('Payment Success: ' . $responseMessage . ', code: '. $responseSummary ); // log to DB
        return $responseParameters;
        break;
      case 'declined':
            // Hard decline from bank.

            //$requestParameters['payment_status_id'] = array_search('Failed', $statuses); // set failed

            $msg = "Your transaction was declined. Error:" . $responseMessage . ', code: '. $responseSummary ;
            Civi::log()->debug('Payment Error: ' . $msg); // log to DB
          
            CRM_Core_Session::setStatus('Transaction has been declined by your financial institution. '.$responseMessage . ', code: '. $responseSummary , 'PayWay', 'error'); // show user error message
            //echo $msg;
            break;
      case 'pending':
            $msg = "Transaction has failed due to a network error, please try again later. Error: " . $responseMessage . ', code: '. $responseSummary ;
            Civi::log()->debug('Payment Error: ' . $msg); // log to DB
            //return paymentErrorExit(9009, $msg );

            CRM_Core_Session::setStatus('Transaction has failed due to a network error, please try again later. '.$responseMessage . ', code: '. $responseSummary , 'PayWay', 'error'); // show user error message
            //echo $msg;
            break;
      case 'approved*':
            $msg = "Transaction may have succeeded or failed. A successful transaction during period when it may be declined or dishonoured. Error:" . $responseMessage . ', code: '. $responseSummary ;
            Civi::log()->debug('Payment Error: ' . $msg); // log to DB
            //return paymentErrorExit(9009, $msg );
            //echo $msg;
            CRM_Core_Session::setStatus('Transaction may have succeeded or failed. A transaction during period when it may be declined or dishonoured. '. $msg);
            break;
      default :
            $msg = "Transaction has failed. Error:" . $responseMessage . ', code: '. $responseSummary ;
            Civi::log()->debug('Payment Error: ' . $msg); // log to DB
            //return paymentErrorExit(9009, $msg );
            //echo $msg;
            CRM_Core_Session::setStatus('Transaction has failed. '.$msg, 'PayWay', 'error');
    }
    Civi::log()->debug('Transaction Info: ' . print_r($responseParameters, true) ); // log to DB
    //Civi::log()->debug('Payment Error: ' . $msg); // log to DB
    //generateErrorMessage($url,$msg);
    //exit();

    Civi::log()->debug('Success_redirect',['url' => $url]); // log to DB
    CRM_Utils_System::redirect($url);
  }
  
}

function generateErrorMessage($url,$msg) {
    echo '<h2>Error</h2>';
    echo '<p>'. $msg . '</p>';
    echo '<p><a href="'. $url .'">Back</a></p>';
}


/**
 * 
 */
function sendNotificationEmail($response,$admin,$message = null) {

  if ($response) {
    // get values from response
    $transactionId  = get_value( $response, 'transactionId' ); 
    $paymentAmount  = number_format(get_value( $response, 'paymentAmount' ), 2, '.', '');
    $status         = get_value( $response, 'status' ); 
    $responseCode   = get_value( $response, 'responseCode' ); 
    $responseText   = get_value( $response, 'responseText' ); 
    $customerNumber  = get_value( $response, 'customerNumber' ); 
    $customerName   = get_value( $response, 'customerName' ); 
    $cardScheme     = get_value( $response, 'creditCard.cardScheme' ); 
    $cardNumber     = get_value( $response, 'creditCard.cardNumber' ); 
    $transDateTime  = get_value( $response, 'transactionDateTime' ); 
    $settlementDate  = get_value( $response, 'settlementDate' ); 
    $receiptNumber  = get_value( $response, 'receiptNumber' ); 

    $transactionType  = get_value( $response, 'transactionType' ); 
    $merchantId     = get_value( $response, 'merchant.merchantId' ); 
    $currency       = get_value( $response, 'currency' ); 
    $paymentMethod  = get_value( $response, 'paymentMethod' ); 
    
  } else {
    return;
  }

  // Multiple recipients
  $to = $admin; // use comma for multiple recipients

  // Subject
  $subject = 'Payment Receipt for '. $customerName . ' - ' . $status;

$msg = 'We have processed your payment of ' . $currency . ' $' . $paymentAmount . '<br/><br/>' .
'Status: ' . $status . '<br/>' .
'Honour with identification (' . $responseCode . ')' . '<br/><br/>' .

'Customer Reference Number:	' . $customerNumber . '<br/>' .
'Card Holder Name:	' . $customerName . '<br/>' .
'Card Number:	' . $cardNumber . '<br/>' .
'Card Type:	' . $cardScheme . '<br/>' .
'Account Type:	' . $paymentMethod . '<br/>' .
'Merchant Id:	' . $merchantId	. '<br/>' .
'Transaction Date/Time:	' . $transDateTime . '<br/>' .
'Settlement Date:	' . $settlementDate . '<br/>' .
'Receipt No:	' . $receiptNumber . '<br/><br/>' .
'Transaction Source: Net <br/>' .
'Transaction Amount:	' . $currency . ' $' . $paymentAmount . '<br/>' .
'Transaction Type:	' . $transactionType . '<br/>' ;

  if ($merchantId == 'TEST') {
    $mode = '<p>NOTE: This is a TEST payment only. It was not processed by the banking network. It will not appear on the cardholder\'s statement or settle to the merchant account.</p>';
  } else {
    $mode = '';
  }

  // Message body
  $message = '
  <html>
  <head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>'. $subject .'</title>
  </head>
  <body style="padding:20px; margin:0px; font-family:sans, Arial, Helvetica; font-size:14px; background:#f5f5f5; color:#333;">
    <center>
    <table width="600" border="0" cellpadding="0" cellspacing="0" style="width:650px; font-family:sans, Arial, Helvetica; font-size:14px; background:#ffffff; color:#333;">
    <tr>
        <td style="padding:20px; border-bottom: 1px solid #666;"><h1 style="margin:10px 0px; padding:10px 0px;">Payment notification</h1>'. $mode .'</td>
      </tr>  
    <tr>
        <td style="padding:20px 20px 10px;"><p><strong>'. $subject .'</strong></p></td>
      </tr>
      <tr>
        <td style="padding:10px 20px 20px;">'. $msg .'</td>
      </tr>
      <tr>
        <td style="padding:20px; background-color:#666; color:#fff;">Notification sent at ' . date("h:i:sa") . '</td>
      </tr>
    </table>
    </center>
  </body>
  </html>
  ';

  // To send HTML mail, the Content-type header must be set
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-type: text/html; charset=iso-8859-1';

  // Additional headers
  //$headers[] = 'To: ' . $to;
  $headers[] = 'From: ANZSGM Website <admin@anzsgm.org>';
  //$headers[] = 'Cc: someone@example.com';
  if ($merchantId == 'TEST') {
    $headers[] = 'Bcc: cfrost@fi.net.au';
  }

  // Mail it
  $sent = mail($to, $subject, $message, implode("\r\n", $headers));

  if (!$sent) {
      $errorMessage = error_get_last()['message'];
      print_r($errorMessage);
      exit();
  }
  
 }

 /**
  * Check if email is valid
  */
function checkValidEmail($email) {
    return !!filter_var($email, FILTER_VALIDATE_EMAIL);
} 

/**
   * Produces error message and returns from class
   *
   * @param null $errorCode
   * @param null $errorMessage
   *
   * @return object
   */
  function paymentErrorExit($errorCode = NULL, $errorMessage = NULL) {
    $e = CRM_Core_Error::singleton();

    if ($errorCode) {
      $e->push($errorCode, 0, NULL, $errorMessage);
    }
    else {
      $e->push(9000, 0, NULL, 'Unknown System Error.');
    }
    return $e;
  }

/**
 * GET TOKEN
 * https://www.payway.com.au/docs/rest.html#tokenise-credit-card-mobile-app-
 */
function getSingsleUseTokenID($post_data,$key) {

  $headers[] = "Authorization: Basic " . base64_encode($key . ":");
  $headers[] = "Content-Type: application/x-www-form-urlencoded";

  /* all required fields
  $post_data = array(
      "paymentMethod" => "creditCard",
      "cardNumber" => "4564710000000004",
      "cardholderName" => "TEST PERSON",
      "cvn" => "847",
      "expiryDateMonth" => "02",
      "expiryDateYear" => "29",
  );
  */

  $result = CurlExecutor::execute(BASE_URL . "/single-use-tokens", "POST", $post_data, null, $headers);
  $result["response"] = json_decode($result["response"]);
  if ($result["code"] == 200) {
      return $result["response"]->singleUseTokenId;
  } else {
    CurlExecutor::prettyPrint($result);
    die("Error");
  }
  
}

/**
 * PROCESS PAYMENT
 * https://www.payway.com.au/docs/rest.html#take-payment
 * Uses token to process payment
 */
function createSinglePayment($token,$post_data,$key) {

  $headers[] = "Authorization: Basic " . base64_encode($key . ":");
  $headers[] = "Content-Type: application/x-www-form-urlencoded";
  
  /* required fields
  $post_data = array(
      "singleUseTokenId" => $token,
      "customerNumber" => "120",
      "transactionType" => "payment",
      "principalAmount" => "20.00",
      "currency" => "aud",
      "orderNumber" => "Order-13",
      "merchantId" => MERCHANT_ID
  );
  */

  $result = CurlExecutor::execute(BASE_URL . "/transactions", "POST", $post_data, null, $headers);
  $result["response"] = json_decode($result["response"]);

  //return CurlExecutor::prettyPrint($result);
  return $result;
}
/****
 * UTILITIES
 */

/**
 * Get a value from an object or an array.  Allows the ability to fetch a nested value from a
 * heterogeneous multidimensional collection using dot notation.
 *
 * @param array|object $data
 * @param string       $key
 * @param mixed        $default
 * @return mixed
 */
function get_value( $data, $key, $default = null ) {
	$value = $default;
	if ( is_array( $data ) && array_key_exists( $key, $data ) ) {
		$value = $data[$key];
	} else if ( is_object( $data ) && property_exists( $data, $key ) ) {
		$value = $data->$key;
	} else {
		$segments = explode( '.', $key );
		foreach ( $segments as $segment ) {
			if ( is_array( $data ) && array_key_exists( $segment, $data ) ) {
				$value = $data = $data[$segment];
			} else if ( is_object( $data ) && property_exists( $data, $segment ) ) {
				$value = $data = $data->$segment;
			} else {
				$value = $default;
				break;
			}
		}
	}
	return $value;
}