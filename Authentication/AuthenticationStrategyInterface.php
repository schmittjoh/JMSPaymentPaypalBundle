<?php

namespace Bundle\PayPalPaymentBundle\Authentication;

use Bundle\PaymentBundle\BrowserKit\Request;

interface AuthenticationStrategyInterface
{
    function getApiEndpoint($isDebug);
    function authenticate(Request $request);
}