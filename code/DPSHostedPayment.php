<?php
/**
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
class DPSHostedPayment extends Payment{
	
	static $pxAccess_Url = "https://sec.paymentexpress.com/pxpay/pxpay.aspx";
	
	private static $pxAccess_Userid;
	
	private static $pxAccess_Key;
	
	private static $mac_Key;
	
	static $pxPay_Url  = "https://sec.paymentexpress.com/pxpay/pxaccess.aspx";
  	
	private static $pxPay_Userid;
  	
	private static $pxPay_Key;
	
	public static $px_currency = 'NZD';
		
	/**
	 * @var string $px_merchantreference Reference field to appear on transaction reports
	 */
	public static $px_merchantreference = null;
	
	protected static $use_iframe = false;
	
	static $db = array(
		'TxnRef' => 'Varchar', // only written on success
		'AuthorizationCode' => 'Varchar', // only written on success
		'TxnID' => 'Varchar' // random number
	);
	
	static $has_one = array();
	
	function getPaymentFormFields(){
		return new FieldSet();
	}
	
	function getPaymentFormRequirements(){
		return array();
	}
	
	static function set_px_access_userid($id){
		self::$pxAccess_Userid = $id;
	}
	
	static function get_px_access_userid(){
		return self::$pxAccess_Userid;
	}
	
	static function set_px_access_key($key){
		self::$pxAccess_Key = $key;
	}
	
	static function get_px_access_key(){
		return self::$pxAccess_Key;
	}
	
	static function set_mac_key($key){
		self::$mac_Key = $key;
	}
	
	static function get_mac_key(){
		return self::$mac_Key;
	}
	
	static function set_px_pay_userid($id){
		self::$pxPay_Userid = $id;
	}
	
	static function get_px_pay_userid(){
		return self::$pxPay_Userid;
	}
	
	static function set_px_pay_key($key){
		self::$pxPay_Key = $key;
	}
	
	static function get_px_pay_key(){
		return self::$pxPay_Key;
	}
	
	static function set_use_iframe($use = true){
		self::$use_iframe = $use;
	}
		
	static function generate_txn_id() {
		do {
			$rand = rand();
			$idExists = (bool)DB::query("SELECT COUNT(*) FROM `DPSHostedPayment` WHERE `TxnID` = '{$rand}'")->value();
		} while($idExists);
		return $rand;
	}
	
	/**
	 * Executed in form submission *before* anything
	 * goes out to DPS.
	 */
	public function processPayment($data, $form){
		// generate a unique transaction ID
		$this->TxnID = DPSHostedPayment::generate_txn_id();
		$this->write();
		
		// generate request from thirdparty pxpayment classes
		$request = $this->prepareRequest($data);
		
		// decorate request (if necessary)
		$this->extend('prepareRequest', $request);
		
		// set currency
		$this->Amount->Currency = $request->getInputCurrency();
	
		// submit payment request to get the URL for redirection
		$pxpay = new PxPay(self::$pxPay_Url, self::$pxPay_Userid, self::$pxPay_Key);
		$request_string = $pxpay->makeRequest($request);

		$response = new MifMessage($request_string);
		$url = $response->get_element_text("URI");
		$valid = $response->get_attribute("valid");
		
		// set status to pending
		if($valid) {
			$this->Status = 'Pending';
			$this->write();
		}
		
		//provide iframe with payment gateway form in it	
		if(self::$use_iframe){
			return new Payment_Processing(array(
				'Content' => "<iframe src =\"$url\" width=\"100%\" height=\"380\" frameborder=\"0\" name=\"payframe\"><a href=\"$url\">"._t('DPSHostedPayment.CLICKHERE',"click here to pay")."</a></iframe>"
			));
		}
		Director::redirect($url);
		return new Payment_Processing();
	}
	
	/**
	 * Generate a {@link PxPayRequest} object and populate it with the submitted
	 * data from a instance.
	 * 
	 * @see http://www.paymentexpress.com/technical_resources/ecommerce_hosted/pxpay.html#GenerateRequest
	 * 
	 * @param array $data
	 * @return PxPayRequest
	 */
	protected function prepareRequest($data){
		$request = new PxPayRequest();
		
		// Set in payment_dpshosted/_config.php
		$postProcess_url = Director::absoluteBaseURL() .DPSHostedPayment_Controller::$URLSegment."/processResponse";
		$request->setUrlFail($postProcess_url);
		$request->setUrlSuccess($postProcess_url);
		
		// set amount
		$request->setAmountInput($this->Amount->Amount);
		
		// mandatory free text data
		if(isset($data['FirstName']) && isset($data['Surname'])) {
			$request->setTxnData1($data['FirstName']." ".$data['Surname']);
			$request->setTxnData2($this->ID);
			//$request->setTxnData3();
		}
		
		// Auth, Complete, Purchase, Refund (DPS recomend completeing refunds through other API's)
		$request->setTxnType('Purchase'); // mandatory
		
		// randomly generated number from {@link processPayment()}
		$request->setTxnId($this->TxnID);
		
		// defaults to NZD
		$request->setInputCurrency(self::$px_currency); // mandatory
		
		// use website URL as a reference if none is given
		$ref = Director::absoluteBaseURL();
		if(self::$px_merchantreference){
			$ref = sprintf(self::$px_merchantreference,$this->PaidForID);
		}
		elseif($this->PaidObject() && $name = $this->PaidObject()->singular_name()){
			$ref .= $name.$this->PaidForID;
		}
		else{
			$ref =  Director::absoluteBaseURL();
		}

		$request->setMerchantReference($ref); // mandatory			
		
		if(isset($data['Email'])) {
			$request->setEmailAddress($data['Email']); // optional
		}
		
		return $request;
	}

}

class DPSHostedPayment_Controller extends Controller {
	
	static $URLSegment = 'paymentexpressctl';
	
	/**
	 * React to DSP response triggered by {@link processPayment()}.
	 */
	public function processResponse() {
		if(preg_match('/^PXHOST/i', $_SERVER['HTTP_USER_AGENT'])){
			$dpsDirectlyConnecting = 1;
		}

		//$pxaccess = new PxAccess($PxAccess_Url, $PxAccess_Userid, $PxAccess_Key, $Mac_Key);

		$pxpay = new PxPay(
			DPSHostedPayment::$pxPay_Url, 
			DPSHostedPayment::get_px_pay_userid(), 
			DPSHostedPayment::get_px_pay_key()
		);

		$enc_hex = $_REQUEST["result"];

		$rsp = $pxpay->getResponse($enc_hex);

		if(isset($dpsDirectlyConnecting) && $dpsDirectlyConnecting) {
			// DPS Service connecting directly
			$success = $rsp->getSuccess();   # =1 when request succeeds
			echo ($success =='1') ? "success" : "failure";
		} else {
			// Human visitor
			$paymentID = $rsp->getTxnId();
			$SQL_paymentID = (int)$paymentID;

			$payment = DataObject::get_one('DPSHostedPayment', "`TxnID` = '$SQL_paymentID'");
			if(!$payment) {
				// @todo more specific error messages
				return array(
					'RedirectLink' => AccountPage::find_link() //TODO: give "no payment" error + send error to webmaster
				);
			}
			

			$success = $rsp->getSuccess();
			if($success =='1'){
				// @todo Use AmountSettlement for amount setting?
				$payment->TxnRef=$rsp->getDpsTxnRef();
				$payment->Status = "Success";
				$payment->AuthorizationCode=$rsp->getAuthCode();
				
			} else {
				$payment->Message=$rsp->getResponseText();
				$payment->Status="Failure";
			}
			$payment->write();
			
			//TODO: this needs to be generalised in Payment??
			$redirectURL = ($payment->PaidObject() && $payment->PaidObject()->Link()) ? $payment->PaidObject()->Link() : 'home';
			
			Director::redirect($redirectURL);
			return null;
		}
	}
}