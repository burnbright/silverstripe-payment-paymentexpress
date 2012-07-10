# Payment Express (DPS)

Payment Express PxPay hosted payment method.

More information about pxpay can be found here:
http://www.paymentexpress.com/technical_resources/ecommerce_hosted/pxpay.html

## Setup

Add 'PaymentExpressPxPay' to your supported payment types in your
mysite/_config/payment.yaml file:

	PaymentProcessor:
	  supported_methods:
	    - 'PaymentExpressPxPay'

Also set your payment express user id and pay key by adding them to your 
mysite/_config/payment.yaml file:

	PaymentExpressPxPayGateway:
	    userid: UserID
	    key: b032h3lsl0a340hgla39ag9a3hl2gol939gagao4ga3w4ga3l4lwfweg

For an example, see _config/payment.yaml.example	

## Testing details

Use an amount ending in .76 to generate a declined transaction.
  
Test Credit Cards:

	4111111111111111 - visa
	5431111111111111 - mastercard
	371111111111114  - amex
	4444555566669999 - invalid card type
	4999999999999202 - declined, with retry = 1