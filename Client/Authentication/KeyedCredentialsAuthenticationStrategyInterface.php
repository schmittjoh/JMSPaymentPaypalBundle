<?php

namespace JMS\Payment\PaypalBundle\Client\Authentication;

use JMS\Payment\CoreBundle\BrowserKit\Request;

interface KeyedCredentialsAuthenticationStrategyInterface
{
    /**
     * @param Request $request
     * @param         $key
     */
    public function authenticateWithKeyedCredentials(Request $request, $key);
}
