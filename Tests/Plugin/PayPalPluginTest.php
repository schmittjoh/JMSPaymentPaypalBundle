<?php

namespace Bundle\PayPalPaymentBundle\Tests\Plugin;

use Bundle\PayPalPaymentBundle\Authentication\TokenAuthenticationStrategy;

class PayPalPluginTest extends \PHPUnit_Framework_TestCase
{
    public function testRequestSetExpressCheckout()
    {
        $plugin = $this->getPlugin();
        $response = $plugin->requestSetExpressCheckout(123.43, 'http://www.foo.com/returnUrl', 'http://www.foo.com/cancelUrl');
        
        $this->assertTrue($response->body->has('TOKEN'));
        $this->assertTrue($response->isSuccess());
        $this->assertEquals('Success', $response->body->get('ACK'));
    }
    
    public function testRequestGetExpressCheckoutDetails()
    {
        $plugin = $this->getPlugin();
        $token = $plugin->requestSetExpressCheckout('123', 'http://www.foo.com/', 'http://www.foo.com/')->body->get('TOKEN');
        
        $response = $plugin->requestGetExpressCheckoutDetails($token);
        
        $this->assertTrue($response->body->has('TOKEN'));
        $this->assertTrue($response->body->has('CHECKOUTSTATUS'));
        $this->assertEquals('PaymentActionNotInitiated', $response->body->get('CHECKOUTSTATUS'));
        $this->assertEquals('Success', $response->body->get('ACK'));
    }
    
    protected function getStrategy()
    {
        // sorry, only sandbox credentials here :)
        return new TokenAuthenticationStrategy(
            'schmit_1283340315_biz_api1.gmail.com', 
            '1283340321',
            'A93vj6VJ.ZIRNjbI6GFgi4N2Km.5ATLs-EinlyWk2htEGX0xc3L8YIBo'
        );
    }
    
    protected function getPlugin()
    {
        $mock = $this->getMockForAbstractClass(
        	'Bundle\PayPalPaymentBundle\Plugin\PayPalPlugin',
            array($this->getStrategy(), true)
        );
        
        $transaction = $this->getMock('Bundle\PaymentBundle\Model\FinancialTransactionInterface');
        $reflection = new \ReflectionProperty($mock, 'currentTransaction');
        $reflection->setAccessible(true);
        $reflection->setValue($mock, $transaction);
        $reflection->setAccessible(false);
        
        return $mock;
    }
}