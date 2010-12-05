<?php

namespace Bundle\PayPalPaymentBundle\Authentication;

use Bundle\PaymentBundle\BrowserKit\Request;
use Bundle\PayPalPaymentBundle\Authentication\AuthenticationStrategyInterface;

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
        $header = 'PWD='.urlencode($this->password)
                 .'&USER='.urlencode($this->username)
                 .'&SIGNATURE='.urlencode($this->signature)
        ;
        
        $request->headers->set('X-PP-AUTHORIZATION', $header);
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