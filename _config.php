<?php

Director::addRules(50, array(
	DPSHostedPayment_Controller::$URLSegment . '/$Action/$ID/$OtherID' => 'DPSHostedPayment_Controller'
));