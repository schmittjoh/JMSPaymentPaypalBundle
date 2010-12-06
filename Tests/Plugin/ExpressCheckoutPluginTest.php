<?php

namespace Bundle\PayPalPaymentBundle\Tests\Plugin;

use Bundle\PaymentBundle\Plugin\Exception\ActionRequiredException;
use Bundle\PayPalPaymentBundle\Gateway\Response;
use Bundle\PaymentBundle\Entity\FinancialTransaction;
use Bundle\PaymentBundle\Entity\Payment;
use Bundle\PaymentBundle\Entity\ExtendedData;
use Bundle\PaymentBundle\Entity\PaymentInstruction;

class ExpressCheckoutPluginTest extends \PHPUnit_Framework_TestCase
{
    public function testApproveGeneratesExpressCheckoutUrl()
    {
        $plugin = $this->getPlugin(array('requestSetExpressCheckout'));
        $plugin
            ->expects($this->once())
            ->method('requestSetExpressCheckout')
            ->with($this->equalTo(123.45), $this->equalTo('returnUrl'), $this->equalTo('cancelUrl'), $this->equalTo(array(
                'PAYMENTREQUEST_0_PAYMENTACTION' => 'Authorization',
                'PAYMENTREQUEST_0_CURRENCYCODE'  => 'EUR',
            )))
            ->will($this->returnValue(new Response(array('TOKEN' => 'foo'))))
        ;
        
        $data = new ExtendedData();
        $transaction = $this->getTransaction(123.45, 'EUR', $data);
        
        try {
            $plugin->approve($transaction, false);
            $this->fail('Plugin was expected to throw an exception.');
        }
        catch (ActionRequiredException $ex) {
            $this->assertSame($transaction, $ex->getFinancialTransaction());
            
            $action = $ex->getAction();
            $this->assertInstanceOf('Bundle\PaymentBundle\Plugin\Exception\Action\VisitUrl', $action);
            $this->assertNotEmpty($action->getUrl());
            $this->assertEquals('foo', $data->get('express_checkout_token'));
            $this->assertTrue($data->isEncryptionRequired('express_checkout_token'));
        }
    }
    
    protected function getTransaction($amount, $currency, $data)
    {
        $paymentInstruction = new PaymentInstruction($amount, $currency, 'paypal_express_checkout', $data);
        $payment = new Payment($paymentInstruction, $amount);
        $transaction = new FinancialTransaction();
        $transaction->setRequestedAmount($amount);
        $payment->addTransaction($transaction);
        
        return $transaction;
    }
    
    protected function getPlugin(array $methods = array())
    {
        $mock = $this->getMockBuilder('Bundle\PayPalPaymentBundle\Plugin\ExpressCheckoutPlugin')
                ->disableOriginalConstructor()
                ->setMethods($methods)
                ->getMock()
        ;
        
        $reflection = new \ReflectionProperty($mock, 'returnUrl');
        $reflection->setAccessible(true);
        $reflection->setValue($mock, 'returnUrl');
        $reflection->setAccessible(false);
        
        $reflection = new \ReflectionProperty($mock, 'cancelUrl');
        $reflection->setAccessible(true);
        $reflection->setValue($mock, 'cancelUrl');
        $reflection->setAccessible(false);
        
        return $mock;
    }
}