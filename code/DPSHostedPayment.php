<?php
// modifed by torleif 2009/01/05 - added a order module to payment system. Ensures that items bought get added somewere.
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
 * Testing details
 * 
 * Use an amount ending in .76 to generate a declined transaction.
 * 
 * Credit Cards: 4111111111111111 - visa
 *				 5431111111111111 - mastercard
 *				 371111111111114  - amex
				 4444555566669999 - invalid card type
				 4999999999999202 - declined, with retry = 1
 * 
 */
class DPSHostedPayment extends Payment{
	
	static $pxAccess_Url = "https://www.paymentexpress.com/pxpay/pxpay.aspx";
	
	private static $pxAccess_Userid;
	
	private static $pxAccess_Key;
	
	private static $mac_Key;
	
	static $pxPay_Url  = "https://www.paymentexpress.com/pxpay/pxaccess.aspx";
  	
	private static $pxPay_Userid;
  	
	private static $pxPay_Key;
	
	public static $px_currency = 'NZD';
		
	/**
	 * @var string $px_merchantreference Reference field to appear on transaction reports
	 */
	public static $px_merchantreference = null;
	
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
		//TODO: make this custom // move elsewhere
		return new Payment_Processing(array(
			'DeliveryMenuStatus' => 'done',
			'PaymentMenuStatus' => 'current',
			'PaymentIFrame' => "<iframe src =\"$url\" width=\"100%\" height=\"380\" frameborder=\"0\" name=\"payframe\"><a href=\"$url\">click here to pay</a></iframe>"
		));
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
		$postProcess_url = Director::absoluteBaseURL() ."DPSHostedPayment/processResponse";
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
	
	/**
	 * Set the IP address and Proxy IP (if available) from the site visitor.
	 * Does an ok job of proxy detection. Probably can't be too much better because anonymous proxies
	 * will make themselves invisible.
	 */	
	function setClientIP() {
		if(isset($_SERVER['HTTP_CLIENT_IP'])) $ip = $_SERVER['HTTP_CLIENT_IP'];
		else if(isset($_SERVER['REMOTE_ADDR'])) $ip = $_SERVER['REMOTE_ADDR'];
		else $ip = null;
		
		if(isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$proxy = $ip;
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		}
		
		// If the IP and/or Proxy IP have already been set, we want to be sure we don't set it again.
		if(!$this->IP) $this->IP = $ip;
		if(!$this->ProxyIP && isset($proxy)) $this->ProxyIP = $proxy;
	}
	
	/**
	 * DQL EDIT
	 * @return 
	 */
   function getCMSFields_forPopup() {
      $fields = new FieldSet();
			
			// grab the order with this payments id
			//$OrderInfo = DataObject::get('Order', "ID = {$this->ID}")->First();
			
     // $fields->push( new TextField( 'FirstName', 'First Name', $OrderInfo->ShippingName) );
		 
			$orderForRender = new DataObject();
			$orderForRender->Order = DataObject::get_one('Order', "ID = {$this->ID}");
			$orderForRender->Payment = $this;
				
      $ordersRendered = $orderForRender->renderWith('OrdersCMS');
      $fields->push(new LiteralField("OrderFeild", $ordersRendered));
			
      return $fields;
   }
	 /**
	  * DQL EDIT
	  * @return 
	  */
	public function CompleteLink() {
		if($this->Status == 'SuccessSent') {
			return  "<a href=\"#\" onClick=\"new Ajax.Request('/Orders/setUnBoughtOrder?OrderID=".$this->ID."',	{method: 'get',	onFailure: function(response) {".
								"alert('There was an error updating your order information. Please try again.');	},onComplete: function(response) {		alert(response.responseText);}		} );
	                             this.parentNode.parentNode.style.textDecoration = 'line-through'\">Mark as not complete</a>";
		}
		return  "<a href=\"#\" style=\"font-size:18px\" onClick=\"new Ajax.Request('/Orders/setBoughtOrder?OrderID=".$this->ID."',	{method: 'get',	onFailure: function(response) {".
							"alert('There was an error updating your order information. Please try again.');	},onComplete: function(response) {		alert(response.responseText);}		} );
                             this.parentNode.parentNode.style.textDecoration = 'line-through'\">Mark as complete</a>";
	}
	
	public function PaymentType() {
		if(!$this->MyOrderID || $this->MyOrderID == 0) return 'Invalid Order ID';
		return DataObject::get_by_id('Order', $this->MyOrderID)->PaymentMethod;
	}
}

class DPSHostedPayment_Controller extends Controller {
	
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
			
			//javascript redirect, or provide link to click		
			//redirect to recirectURL. _top is used to be sure main frame redirects, and not iframe.
			Requirements::javascript(THIRDPARTY_DIR.'/jquery/jquery.js');
			$script = <<<JS
				$('#RedirectLink').hide();
				window.open("$redirectURL", '_top','',false);	
JS;
			
			Requirements::customScript($script);
			
			return array(
				'RedirectLink' => $redirectURL
			);
		}
	}
}