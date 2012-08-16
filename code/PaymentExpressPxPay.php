<?php
/**
 * Payment Express Hosted Payment
 * 
 * Step-by-Step:
 * 1. Send XML transaction request (GenerateRequest) to PaymentExpress
 *    => DPSHostedPaymentForm->doPay() => DPSHostedPayment->prepareRequest() => DPSHostedPayment->processPayment()
 * 2. Receive XML response (Request) with the URI element (encrypted URL), which you use to redirect the user to PaymentExpress so they can enter their card details
 * 3. Cardholder enters their details and transaction is sent to your bank for authorisation. The response is given and they are redirected back to your site with the response
 * 4. You take the "Request" parameter (encrypted URL response) in the URL string and use this in the "Response" element, to send the response request (ProcessResponse) to PaymentExpress to decrypt and receive the XML response back.
 * 5. Receive XML response (Response) with the authorised result of the transaction.
 *    => DPSHostedPayment_Controller->processResponse()
 * 
 * @see http://www.paymentexpress.com/technical_resources/ecommerce_hosted/pxpay.html
 * @package payment_dpshosted
 * 
 * Note that this class relies on the PxPay, PxPayRequest, and MifMessage classes found in payment/DPSPayment/dps_hosted_helper/
 * If the payment module ever removes it's existing payment types, then that code will need to be included in this payment type, or a separate module.
 * 
 */

class PaymentExpressPxPay extends Payment{
	static $db = array(
		'TxnRef' => 'Varchar', // only written on success
		'AuthorizationCode' => 'Varchar', // only written on success
		'TxnID' => 'Varchar' // random number
	);
}

/**
 * External gateway hosted payments
 */
class PaymentExpressPxPayGateway extends PaymentGateway_GatewayHosted{

	public static $px_currency = 'NZD'; //TODO: use payment module std approach
	public static $px_merchantreference = null;
		
	private static $pxpay;
	
	protected $userid,$key,$url,$protocol;
	
	function __construct(){
		//get conf for environment
		$config =  Config::inst()->get('PaymentExpressPxPayGateway', self::get_environment());
		//set configs
		$this->userid = isset($config['userid']) ? $config['userid'] : null;
		$this->key = isset($config['key']) ? $config['key'] : null;
		$this->protocol = isset($config['protocol']) ? $config['protocol'] : null;
		//$this->url = Config::inst()->get(get_class($this), 'url'); //not working
		$this->url = "https://sec.paymentexpress.com/pxpay/pxaccess.aspx";
	}

	static function generate_txn_id() {
		do {
			$rand = rand();
			$idExists = (bool)DB::query("SELECT COUNT(*) FROM `PaymentExpressHostedPayment` WHERE `TxnID` = '{$rand}'")->value();
		} while($idExists);
		return $rand;
	}
	
	public function getSupportedCurrencies() {
		return array('USD','NZD','AUD');
	}
	
	/**
	 * Creates the library class for interfacing with PaymentExpress web API.
	 */
	function createPxPay(){
		$protocol = (strtolower($this->protocol) == "openssl") ? "PxPay_OpenSSL" : "PxPay_Curl";
		return new $protocol($this->url, $this->userid, $this->key);
	}
	
	/**
	 * Executed in form submission *before* anything
	 * goes out to PaymentExpress.
	 */
	public function process($data){
		if(!$this->userid || !$this->key || !$this->url){
			user_error("Authentication details not set properly. Please set user id and key for '".get_class($this)."' in config file.", E_USER_ERROR);
		}
		$this->TxnID = self::generate_txn_id(); // generate a unique transaction ID		
		$request = $this->prepareRequest($data); // generate request from thirdparty pxpayment classes
		//$this->Amount->Currency = $request->getCurrencyInput();
		$pxpay = $this->createPxPay(); // submit payment request to get the URL for redirection
		$request_string = $pxpay->makeRequest($request);
		$response = new MifMessage($request_string);
		$url = $response->get_element_text("URI");
		$valid = $response->get_attribute("valid");
		// set status to pending
		if($valid) {
			Controller::curr()->redirect($url);
			return new PaymentGateway_Success(); //TODO: this should be Incomplete, but need to update paymentprocessor if so
		}
		return new PaymentGateway_Failure();
	}
	
	/**
	 * Generate a {@link PxPayRequest} object and populate it with the submitted
	 * data from a instance.
	 * 
	 * @see http://www.paymentexpress.com/technical_resources/ecommerce_hosted/pxpay.html#GenerateRequest
	 * 
	 * @param array $data
	 * @return PxPayRequest
	 * 
	 */
	protected function prepareRequest($data){
		$request = new PxPayRequest();
		// Set in payment_paymentexpress/_config.php
		$request->setUrlFail($this->returnURL);
		$request->setUrlSuccess($this->returnURL);
		// set amount
		$request->setAmountInput($data['Amount']);
		// mandatory free text data
		if(isset($data['FirstName']) && isset($data['Surname'])) {
			$request->setTxnData1($data['FirstName']." ".$data['Surname']);
			$request->setTxnData2($this->ID);
			//$request->setTxnData3();
		}
		// Auth, Complete, Purchase, Refund (PaymentExpress recomend completeing refunds through other API's)
		$request->setTxnType('Purchase'); // mandatory
		// randomly generated number from {@link processPayment()}
		$request->setTxnId($this->TxnID);
		// defaults to NZD
		$request->setCurrencyInput(self::$px_currency); // mandatory
		// use website URL as a reference if none is given
		$ref = Director::absoluteBaseURL();
		if(self::$px_merchantreference){
			$ref = sprintf(self::$px_merchantreference,$this->PaidForID);
		}
		else{
			$ref = Director::absoluteBaseURL();
		}
		$request->setMerchantReference($ref); // mandatory
		if(isset($data['Email'])) {
			$request->setEmailAddress($data['Email']); // optional
		}
		return $request;
	}
	
	function getResponse($request){
		$rsp = $this->createPxPay()->getResponse($_REQUEST["result"]);
		$success = $rsp->getSuccess();
		if($success =='1'){
			//$payment->TxnRef=$rsp->getDpsTxnRef(); //TODO: how to store these?
			//$payment->AuthorizationCode=$rsp->getAuthCode();
			return new PaymentGateway_Success(null,$rsp->getResponseText());
		}
		return new PaymentGateway_Failure(null,$rsp->getResponseText());
	}

}