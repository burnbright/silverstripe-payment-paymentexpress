<?php

Director::addRules(50, array(
	PaymentExpressHostedPayment_Controller::$URLSegment . '/$Action/$ID/$OtherID' => 'PaymentExpressHostedPayment_Controller'
));