<?php

namespace JMS\Payment\PaypalBundle\Tests\Client;

use JMS\Payment\PaypalBundle\Client\Client;

class ClientTest extends \PHPUnit_Framework_TestCase
{
    public function testShouldAllowGetAuthenticateExpressCheckoutTokenUrlInProdMode()
    {
        $expectedUrl = 'https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token=foobar';
        $token = 'foobar';

        $client = $this->getClient($debug = false);

        $this->assertEquals($expectedUrl, $client->getAuthenticateExpressCheckoutTokenUrl($token));
    }

    public function testShouldAllowGetAuthenticateExpressCheckoutTokenUrlInDebugMode()
    {
        $expectedUrl = 'https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token=foobar';
        $token = 'foobar';

        $client = $this->getClient($debug = true);

        $this->assertEquals($expectedUrl, $client->getAuthenticateExpressCheckoutTokenUrl($token));
    }

    public function testGetAuthenticateExpressCheckoutTokenUrlParams()
    {
        $expectedUrl = 'https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token=foobar&param1=foo&param2=bar';
        $token = 'foobar';
        $params = array('param1' => 'foo', 'param2' => 'bar');

        $client = $this->getClient($debug = true);

        $this->assertEquals($expectedUrl, $client->getAuthenticateExpressCheckoutTokenUrl($token, $params));
    }

    private function getClient($debug)
    {
        return new Client($this->createAuthenticationStrategyMock(), $debug);
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|\JMS\Payment\PaypalBundle\Client\Authentication\AuthenticationStrategyInterface
     */
    private function createAuthenticationStrategyMock()
    {
        return $this->getMockBuilder('JMS\Payment\PaypalBundle\Client\Authentication\AuthenticationStrategyInterface')->getMock();
    }
}
