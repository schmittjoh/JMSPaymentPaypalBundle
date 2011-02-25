<?php

namespace JMS\Payment\PaypalBundle\Tests\Plugin;

use JMS\Payment\PaypalBundle\Authentication\TokenAuthenticationStrategy;

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

class PaypalPluginTest extends \PHPUnit_Framework_TestCase
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
            'JMS\Payment\PaypalBundle\Plugin\PaypalPlugin',
            array($this->getStrategy(), true)
        );

        $transaction = $this->getMock('JMS\Payment\CoreBundle\Model\FinancialTransactionInterface');
        $reflection = new \ReflectionProperty($mock, 'currentTransaction');
        $reflection->setAccessible(true);
        $reflection->setValue($mock, $transaction);
        $reflection->setAccessible(false);

        return $mock;
    }
}