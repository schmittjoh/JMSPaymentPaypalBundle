<?php

namespace Bundle\PayPalPaymentBundle\Authentication;

use Bundle\PaymentBundle\BrowserKit\Request;

interface AuthenticationStrategyInterface
{
    function authenticate(Request $request);
}