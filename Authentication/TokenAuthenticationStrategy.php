<?php

namespace Bundle\PaymentBundle\Authentication;

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
        $header = '&PWD='.urlencode($this->password)
                 .'&USER='.urlencode($this->username)
                 .'&SIGNATURE='.urlencode($this->signature)
        ;
        
        $request->headers->set('X-PP-AUTHORIZATION', $header);
    }
}