<?php

namespace JMS\Payment\PaypalBundle\Tests\Paypal;

use JMS\Payment\PaypalBundle\Client\Client;

class ClientTest extends \PHPUnit_Framework_TestCase
{
    public static function provideExpectedAuthenticateExpressCheckoutTokenUrlsDependsOnDebugFlag()
    {
        return array(
            array(true, 'foobar', 'https://www.sandbox.paypal.com'),
            array(false, 'barfoo', 'https://www.paypal.com'),
        );
    }

    public function testShouldAllowGetAuthenticateExpressCheckoutTokenUrlInDebugMode()
    {
        $expectedUrl = 'https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token=foobar';

        $token = 'foobar';

        $client = new Client($this->createAuthenticationStrategyMock(), $debug = false);

        $this->assertEquals($expectedUrl, $client->getAuthenticateExpressCheckoutTokenUrl($token));
    }

    public function testShouldAllowGetAuthenticateExpressCheckoutTokenUrlInProdMode()
    {
        $expectedUrl = 'https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token=foobar';

        $token = 'foobar';

        $client = new Client($this->createAuthenticationStrategyMock(), $debug = true);

        $this->assertEquals($expectedUrl, $client->getAuthenticateExpressCheckoutTokenUrl($token));
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|\JMS\Payment\PaypalBundle\Client\Authentication\AuthenticationStrategyInterface
     */
    public function createAuthenticationStrategyMock()
    {
        return $this->getMock('JMS\Payment\PaypalBundle\Client\Authentication\AuthenticationStrategyInterface');
    }
}
