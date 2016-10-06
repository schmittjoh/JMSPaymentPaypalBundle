<?php

namespace JMS\Payment\PaypalBundle\Tests\Functional\Client;

use JMS\Payment\PaypalBundle\Tests\Functional\FunctionalTest;

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

class ClientTest extends FunctionalTest
{
    /**
     * @var \JMS\Payment\PaypalBundle\Client\Client
     */
    private $client;

    protected function setUp()
    {
        $this->client = $this->getClient();
    }

    public function testRequestSetExpressCheckout()
    {
        $response = $this->client->requestSetExpressCheckout(123.43, 'http://www.foo.com/returnUrl', 'http://www.foo.com/cancelUrl');

        $this->assertInstanceOf('JMS\Payment\PaypalBundle\Client\Response', $response);
        $this->assertTrue($response->body->has('TOKEN'));
        $this->assertTrue($response->isSuccess());
        $this->assertEquals('Success', $response->body->get('ACK'));
    }

    public function testRequestGetExpressCheckoutDetails()
    {
        $response = $this->client->requestSetExpressCheckout('123', 'http://www.foo.com/', 'http://www.foo.com/');

        //guard
        $this->assertInstanceOf('JMS\Payment\PaypalBundle\Client\Response', $response);
        $this->assertTrue($response->body->has('TOKEN'));

        $response = $this->client->requestGetExpressCheckoutDetails($response->body->get('TOKEN'));

        $this->assertTrue($response->body->has('TOKEN'));
        $this->assertTrue($response->body->has('CHECKOUTSTATUS'));
        $this->assertEquals('PaymentActionNotInitiated', $response->body->get('CHECKOUTSTATUS'));
        $this->assertEquals('Success', $response->body->get('ACK'));
    }
}
