<?php

namespace JMS\Payment\PaypalBundle\Tests\Functional\Paypal;

use JMS\Payment\PaypalBundle\Client\Authentication\TokenAuthenticationStrategy;
use JMS\Payment\PaypalBundle\Client\Client;

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

class ClientTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \JMS\Payment\PaypalBundle\Client\Client
     */
    protected $client;

    protected function setUp()
    {
        if (empty($_SERVER['API_USERNAME']) || empty($_SERVER['API_PASSWORD']) || empty($_SERVER['API_SIGNATURE'])) {
            $this->markTestSkipped('In order to run these tests you have to configure: API_USERNAME, API_PASSWORD, API_SIGNATURE parameters in phpunit.xml file');
        }

        $authenticationStrategy = new TokenAuthenticationStrategy(
            $_SERVER['API_USERNAME'],
            $_SERVER['API_PASSWORD'],
            $_SERVER['API_SIGNATURE']
        );

        $this->client = new Client($authenticationStrategy, $debug = true);
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