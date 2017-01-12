<?php

namespace JMS\Payment\PaypalBundle\Tests\Plugin;

use JMS\Payment\CoreBundle\Entity\ExtendedData;
use JMS\Payment\CoreBundle\Entity\FinancialTransaction;
use JMS\Payment\CoreBundle\Entity\Payment;
use JMS\Payment\CoreBundle\Entity\PaymentInstruction;
use JMS\Payment\CoreBundle\Plugin\Exception\ActionRequiredException;
use JMS\Payment\CoreBundle\Plugin\Exception\PaymentPendingException;
use JMS\Payment\PaypalBundle\Client\Response;
use JMS\Payment\PaypalBundle\Plugin\ExpressCheckoutPlugin;

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
    public function testShouldInitiateNewPaymentProcessIfTransactionNotHaveTokenWhileApproving()
    {
        $expectedAmount = 123.45;
        $expectedReturnUrl = 'returnUrl';
        $expectedCancelUrl = 'cancelUrl';
        $expectedOptionalParameters = array(
            'PAYMENTREQUEST_0_PAYMENTACTION' => 'Authorization',
            'PAYMENTREQUEST_0_CURRENCYCODE'  => 'EUR',
            'CUSTOM_PARAMETER' => 'CUSTOM_VALUE',
        );

        $clientMock = $this->createClientMock($mockedMethods = array('requestSetExpressCheckout'));
        $clientMock
            ->expects($this->once())
            ->method('requestSetExpressCheckout')
            ->with(
                $this->equalTo($expectedAmount),
                $this->equalTo($expectedReturnUrl),
                $this->equalTo($expectedCancelUrl),
                $this->equalTo($expectedOptionalParameters)
            )
            ->will($this->returnValue(new Response(array(
                'ACK' => 'Success',
            ))))
        ;

        $plugin = new ExpressCheckoutPlugin($expectedReturnUrl, $expectedCancelUrl, $clientMock);

        $transaction = $this->createTransaction($expectedAmount, 'EUR');
        $transaction->getExtendedData()->set('checkout_params', array(
            'CUSTOM_PARAMETER' => 'CUSTOM_VALUE',
        ));

        try {
            $plugin->approve($transaction, false);

            $this->fail('Plugin was expected to throw an exception.');
        } catch (ActionRequiredException $ex) {
        }
    }

    public function testShouldInitiatePaymentProcessAndSetObtainedTokenIfTransactionNotHaveOneWhileApproving()
    {
        $expectedToken = 'the_express_checkout_token';

        $requestSetExpressCheckoutReturn= new Response(array(
            'TOKEN' => $expectedToken,
            'ACK' => 'Success',
        ));

        $clientMock = $this->createClientMock($mockedMethods = array('requestSetExpressCheckout'));
        $clientMock
            ->expects($this->once())
            ->method('requestSetExpressCheckout')
            ->will($this->returnValue($requestSetExpressCheckoutReturn))
        ;

        $plugin = new ExpressCheckoutPlugin('return_url', 'cancel_url', $clientMock);

        $transaction = $this->createTransaction($amount = 100, 'EUR');

        try {
            $plugin->approve($transaction, false);

            $this->fail('Plugin was expected to throw an exception.');
        } catch (ActionRequiredException $ex) {
            $this->assertEquals(
                $expectedToken,
                $transaction->getExtendedData()->get('express_checkout_token')
            );
        }
    }

    public function testThrowVisitUrlActionToAuthenticateTokenWhileApproving()
    {
        $requestSetExpressCheckoutReturn= new Response(array(
            'ACK' => 'Success',
        ));

        $clientMock = $this->createClientMock($mockedMethods = array('requestSetExpressCheckout'));
        $clientMock
            ->expects($this->once())
            ->method('requestSetExpressCheckout')
            ->will($this->returnValue($requestSetExpressCheckoutReturn))
        ;

        $plugin = new ExpressCheckoutPlugin('return_url', 'cancel_url', $clientMock);

        $transaction = $this->createTransaction($amount = 100, 'EUR');

        try {
            $plugin->approve($transaction, false);
            $this->fail('Plugin was expected to throw an exception.');
        } catch (ActionRequiredException $ex) {
            $this->assertSame($transaction, $ex->getFinancialTransaction());
        }
    }

    public function testThrowVisitUrlActionToAuthenticateTokenWithExpectedRedirectedUrl()
    {
        $token = 'thetoken';

        $expectedRedirectUrl = 'http://example.com/authenticate-token';

        $requestSetExpressCheckoutReturn= new Response(array(
            'ACK' => 'Success',
            'TOKEN' => $token,
        ));

        $clientMock = $this->createClientMock($mockedMethods = array('requestSetExpressCheckout', 'getAuthenticateExpressCheckoutTokenUrl'));
        $clientMock
            ->expects($this->once())
            ->method('requestSetExpressCheckout')
            ->will($this->returnValue($requestSetExpressCheckoutReturn))
        ;
        $clientMock
            ->expects($this->once())
            ->method('getAuthenticateExpressCheckoutTokenUrl')
            ->with($this->equalTo($token))
            ->will($this->returnValue($expectedRedirectUrl))
        ;

        $plugin = new ExpressCheckoutPlugin('return_url', 'cancel_url', $clientMock);

        $transaction = $this->createTransaction($amount = 100, 'EUR');

        try {
            $plugin->approve($transaction, false);
            $this->fail('Plugin was expected to throw an exception.');
        } catch (ActionRequiredException $ex) {
            $action = $ex->getAction();
            $this->assertInstanceOf('JMS\Payment\CoreBundle\Plugin\Exception\Action\VisitUrl', $action);
            $this->assertEquals($expectedRedirectUrl, $action->getUrl());
        }
    }

    /**
     * @expectedException JMS\Payment\CoreBundle\Plugin\Exception\FinancialException
     * @expectedExceptionMessage PayPal-Response was not successful
     */
    public function testThrowFinancialExceptionOnInitiatePaymentProcessIfResponseNotSuccessWhileApproving()
    {
        $notSuccessResponse = new Response(array());

        //guard
        $this->assertFalse($notSuccessResponse->isSuccess());

        $clientMock = $this->createClientMock($mockedMethods = array('requestSetExpressCheckout'));
        $clientMock
            ->expects($this->once())
            ->method('requestSetExpressCheckout')
            ->will($this->returnValue($notSuccessResponse))
        ;

        $plugin = new ExpressCheckoutPlugin('return_url', 'cancel_url', $clientMock);

        $transaction = $this->createTransaction($amount = 100, 'EUR');

        $plugin->approve($transaction, false);
        $this->fail('Plugin was expected to throw an exception.');
    }

    public function testThrowVisitUrlActionToAuthenticateTokenIfUserHasNotYetAuthenticateTokenWhileApproving()
    {
        $expectedToken = 'the_express_checkout_token';

        $detailResponse = new Response(array(
            'ACK' => 'Success',
        ));

        $clientMock = $this->createClientMock($mockedMethods = array('requestGetExpressCheckoutDetails', 'requestSetExpressCheckout'));
        $clientMock
            ->expects($this->never())
            ->method('requestSetExpressCheckout')
        ;
        $clientMock
            ->expects($this->once())
            ->method('requestGetExpressCheckoutDetails')
            ->with($this->equalTo($expectedToken))
            ->will($this->returnValue($detailResponse))
        ;

        $plugin = new ExpressCheckoutPlugin('return_url', 'cancel_url', $clientMock);

        $transaction = $this->createTransaction($amount = 100, 'EUR');
        $transaction->getExtendedData()->set('express_checkout_token', $expectedToken);

        try {
            $plugin->approve($transaction, false);
            $this->fail('Plugin was expected to throw an exception.');
        } catch (ActionRequiredException $ex) {
            $this->assertSame($transaction, $ex->getFinancialTransaction());

            $action = $ex->getAction();
            $this->assertInstanceOf('JMS\Payment\CoreBundle\Plugin\Exception\Action\VisitUrl', $action);
            $this->assertNotEmpty($action->getUrl());
        }
    }

    /**
     * @expectedException JMS\Payment\CoreBundle\Plugin\Exception\FinancialException
     * @expectedExceptionMessage PaymentAction failed
     */
    public function testThrowPaymentFailedIfDetailsContainCorrespondingCheckoutStatusWhileApproving()
    {
        $expectedToken = 'the_express_checkout_token';

        $detailResponse = new Response(array(
            'ACK' => 'Success',
            'CHECKOUTSTATUS' => 'PaymentActionFailed',
        ));

        $clientMock = $this->createClientMock($mockedMethods = array('requestGetExpressCheckoutDetails', 'requestSetExpressCheckout'));
        $clientMock
            ->expects($this->never())
            ->method('requestSetExpressCheckout')
        ;
        $clientMock
            ->expects($this->once())
            ->method('requestGetExpressCheckoutDetails')
            ->with($this->equalTo($expectedToken))
            ->will($this->returnValue($detailResponse))
        ;

        $plugin = new ExpressCheckoutPlugin('return_url', 'cancel_url', $clientMock);

        $transaction = $this->createTransaction($amount = 100, 'EUR');
        $transaction->getExtendedData()->set('express_checkout_token', $expectedToken);

        $plugin->approve($transaction, false);
    }

    /**
     * @expectedException JMS\Payment\CoreBundle\Plugin\Exception\FinancialException
     * @expectedExceptionMessage PayPal-Response was not successful
     */
    public function testThrowFinancialExceptionOnRequestingPaymentDetailsWhileApproving()
    {
        $expectedToken = 'the_express_checkout_token';

        $notSuccessResponse = new Response(array());

        //guard
        $this->assertFalse($notSuccessResponse->isSuccess());

        $clientMock = $this->createClientMock($mockedMethods = array('requestGetExpressCheckoutDetails', 'requestSetExpressCheckout'));
        $clientMock
            ->expects($this->never())
            ->method('requestSetExpressCheckout')
        ;
        $clientMock
            ->expects($this->once())
            ->method('requestGetExpressCheckoutDetails')
            ->with($this->equalTo($expectedToken))
            ->will($this->returnValue($notSuccessResponse))
        ;

        $plugin = new ExpressCheckoutPlugin('return_url', 'cancel_url', $clientMock);

        $transaction = $this->createTransaction($amount = 100, 'EUR');
        $transaction->getExtendedData()->set('express_checkout_token', $expectedToken);

        $plugin->approve($transaction, false);
    }

    public function testSetTransactionIdAsReferenceCodeToTransactionIfPaymentStatusPending()
    {
        $expectedTransactionId = 'the_transaction_id';

        $requestGetExpressCheckoutDetailsResponse = new Response(array(
            'ACK' => 'Success',
            'CHECKOUTSTATUS' => 'PaymentCompleted',
        ));

        $requestDoExpressCheckoutPaymentResponse = new Response(array(
            'ACK' => 'Success',
            'PAYMENTINFO_0_PAYMENTSTATUS' => 'Pending',
            'PAYMENTINFO_0_TRANSACTIONID' => $expectedTransactionId,
        ));

        $clientMock = $this->createClientMock($mockedMethods = array(
            'requestGetExpressCheckoutDetails',
            'requestSetExpressCheckout',
            'requestDoExpressCheckoutPayment',
        ));
        $clientMock
            ->expects($this->never())
            ->method('requestSetExpressCheckout')
        ;
        $clientMock
            ->expects($this->once())
            ->method('requestGetExpressCheckoutDetails')
            ->will($this->returnValue($requestGetExpressCheckoutDetailsResponse))
        ;
        $clientMock
            ->expects($this->once())
            ->method('requestDoExpressCheckoutPayment')
            ->will($this->returnValue($requestDoExpressCheckoutPaymentResponse))
        ;

        $plugin = new ExpressCheckoutPlugin('return_url', 'cancel_url', $clientMock);

        $transaction = $this->createTransaction($amount = 100, 'EUR');
        $transaction->getExtendedData()->set('express_checkout_token', 'a_token');

        try {
            $plugin->approve($transaction, false);
            $this->fail('Plugin was expected to throw an exception.');
        } catch (PaymentPendingException $ex) {
            $this->assertEquals($expectedTransactionId, $transaction->getReferenceNumber());
        }
    }

    /**
     * @param $amount
     * @param $currency
     * @param $data
     *
     * @return \JMS\Payment\CoreBundle\Entity\FinancialTransaction
     */
    protected function createTransaction($amount, $currency)
    {
        $transaction = new FinancialTransaction();
        $transaction->setRequestedAmount($amount);

        $paymentInstruction = new PaymentInstruction($amount, $currency, 'paypal_express_checkout', new ExtendedData());

        $payment = new Payment($paymentInstruction, $amount);
        $payment->addTransaction($transaction);

        return $transaction;
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|\JMS\Payment\PaypalBundle\Client\Client
     */
    protected function createClientMock($mockedMethods = array())
    {
        return $this->getMockBuilder('JMS\Payment\PaypalBundle\Client\Client')
            ->disableOriginalConstructor()
            ->setMethods($mockedMethods)
            ->getMock();
    }
}
