<?php

namespace JMS\Payment\PaypalBundle\Tests\Plugin;

use JMS\Payment\CoreBundle\Plugin\Exception\ActionRequiredException;
use JMS\Payment\PaypalBundle\Gateway\Response;
use JMS\Payment\CoreBundle\Entity\FinancialTransaction;
use JMS\Payment\CoreBundle\Entity\Payment;
use JMS\Payment\CoreBundle\Entity\ExtendedData;
use JMS\Payment\CoreBundle\Entity\PaymentInstruction;

/*
 * Copyright 2010 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

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
            $this->assertInstanceOf('JMS\Payment\CoreBundle\Plugin\Exception\Action\VisitUrl', $action);
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
        $mock = $this->getMockBuilder('JMS\Payment\PaypalBundle\Plugin\ExpressCheckoutPlugin')
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