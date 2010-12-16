<?php

namespace Bundle\JMS\Payment\PayPalPaymentBundle\Authentication;

use Bundle\JMS\Payment\CorePaymentBundle\BrowserKit\Request;

interface AuthenticationStrategyInterface
{
    function getApiEndpoint($isDebug);
    function authenticate(Request $request);
}