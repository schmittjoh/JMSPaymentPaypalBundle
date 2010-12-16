<?php

namespace Bundle\JMS\Payment\PayPalPaymentBundle\Authentication;

use Bundle\JMS\Payment\CorePaymentBundle\BrowserKit\Request;

class TokenAuthenticationStrategy implements AuthenticationStrategyInterface
{
    protected $username;
    protected $password;
    protected $signature;
    
    public function __construct($username, $password, $signature)
    {
        $this->username = $username;
        $this->password = $password;
        $this->signature = $signature;
    }
    
    public function authenticate(Request $request)
    {
        $request->request->set('PWD', $this->password);
        $request->request->set('USER', $this->username);
        $request->request->set('SIGNATURE', $this->signature);
    }
    
    public function getApiEndpoint($isDebug)
    {
        if ($isDebug) {
            return 'https://api-3t.sandbox.paypal.com/nvp';
        }
        else {
            return 'https://api-3t.paypal.com/nvp';
        }
    }
}