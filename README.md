# Payment Express (DPS)

PxPay hosted payment type.

More information about pxpay can be found here:
http://www.paymentexpress.com/technical_resources/ecommerce_hosted/pxpay.html

Setup
-----

Add 'PaymentExpressHostedPayment' to your supported payment types in your _config file, eg:

	Payment::set_supported_methods(array(
		'PaymentExpressHostedPayment' => 'Credit Card (via Payment Express)'
	));

Set your payment express user id and pay key with the following lines:

	PaymentExpressHostedPayment::set_px_pay_userid('UserID');
	PaymentExpressHostedPayment::set_px_pay_key('b032h3lsl0a340hgla39ag9a3hl2gol939gagao4ga3w4ga3l4l');
	
Note: it would be wise to start by using a test account initially.

You can use either cURL (default) or OpenSSL as the protocol for talking to PaymentExpress servers.
To set OpenSSL as the protocol, add the following to your _config:

	PaymentExpressHostedPayment::set_protocol('OpenSSL');

Testing details
---------------  
Use an amount ending in .76 to generate a declined transaction.
  
Test Credit Cards:

	4111111111111111 - visa
	5431111111111111 - mastercard
	371111111111114  - amex
	4444555566669999 - invalid card type
	4999999999999202 - declined, with retry = 1

## Upgrading

You may need to rename your DPSHostedPayment table to PaymentExpressHostedPayment.