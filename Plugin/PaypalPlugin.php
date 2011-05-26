<?php

namespace JMS\Payment\PaypalBundle\Plugin;

use JMS\Payment\CoreBundle\Model\CreditInterface;
use JMS\Payment\CoreBundle\Model\PaymentInterface;
use JMS\Payment\CoreBundle\Plugin\Exception\FunctionNotSupportedException;
use JMS\Payment\CoreBundle\Model\PaymentInstructionInterface;
use JMS\Payment\PaypalBundle\Gateway\Response;
use JMS\Payment\PaypalBundle\Gateway\ErrorResponse;
use JMS\Payment\CoreBundle\Plugin\QueryablePluginInterface;
use JMS\Payment\CoreBundle\BrowserKit\Request;
use JMS\Payment\CoreBundle\Plugin\GatewayPlugin;
use JMS\Payment\PaypalBundle\Authentication\AuthenticationStrategyInterface;
use JMS\Payment\PaypalBundle\Plugin\Exception\InvalidPayerException;
use JMS\Payment\CoreBundle\Entity\FinancialTransaction;
use JMS\Payment\CoreBundle\Plugin\Exception\FinancialException;
use JMS\Payment\CoreBundle\Plugin\Exception\InternalErrorException;
use JMS\Payment\CoreBundle\Plugin\Exception\CommunicationException;
use JMS\Payment\CoreBundle\Model\FinancialTransactionInterface;

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

/**
 * Implements the NVP API but does not perform any actual transactions
 *
 * @see https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/howto_api_reference
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
abstract class PaypalPlugin extends GatewayPlugin
{
    const API_VERSION = '65.1';

    protected $authenticationStrategy;
    protected $currentTransaction;

    public function __construct(AuthenticationStrategyInterface $authenticationStrategy, $isDebug)
    {
        parent::__construct($isDebug);

        $this->authenticationStrategy = $authenticationStrategy;
    }

    public function requestAddressVerify($email, $street, $postalCode)
    {
        return $this->sendApiRequest(array(
            'METHOD' => 'AddressVerify',
            'EMAIL'  => $email,
            'STREET' => $street,
            'ZIP'    => $postalCode,
        ));
    }

    public function requestBillOutstandingAmount($profileId, array $optionalParameters = array())
    {
        return $this->sendApiRequest(array_merge($optionalParameters, array(
            'METHOD' => 'BillOutstandingAmount',
            'PROFILEID' => $profileId,
        )));
    }

    public function requestCreateRecurringPaymentsProfile($token)
    {
        return $this->sendApiRequest(array(
            'METHOD' => 'CreateRecurringPaymentsProfile',
            'TOKEN' => $token,
        ));
    }

    public function requestDoAuthorization($transactionId, $amount, array $optionalParameters = array())
    {
        return $this->sendApiRequest(array_merge($optionalParameters, array(
            'METHOD' => 'DoAuthorization',
            'TRANSACTIONID' => $transactionId,
            'AMT' => $amount,
        )));
    }

    public function requestDoCapture($authorizationId, $amount, $completeType, array $optionalParameters = array())
    {
        return $this->sendApiRequest(array_merge($optionalParameters, array(
            'METHOD' => 'DoCapture',
            'AUTHORIZATIONID' => $authorizationId,
            'AMT' => $amount,
            'COMPLETETYPE' => $completeType,
        )));
    }

    public function requestDoDirectPayment($ipAddress, array $optionalParameters = array())
    {
        return $this->sendApiRequest(array_merge($optionalParameters, array(
            'METHOD' => 'DoDirectPayment',
            'IPADDRESS' => $ipAddress,
        )));
    }

    public function requestDoExpressCheckoutPayment($token, $amount, $paymentAction, $payerId, array $optionalParameters = array())
    {
        return $this->sendApiRequest(array_merge($optionalParameters, array(
            'METHOD' => 'DoExpressCheckoutPayment',
            'TOKEN'  => $token,
            'PAYMENTREQUEST_0_AMT' => $amount,
            'PAYMENTREQUEST_0_PAYMENTACTION' => $paymentAction,
            'PAYERID' => $payerId,
        )));
    }

    public function requestDoVoid($authorizationId, array $optionalParameters = array())
    {
        return $this->sendApiRequest(array_merge($optionalParameters, array(
            'METHOD' => 'DoVoid',
            'AUTHORIZATIONID' => $authorizationId,
        )));
    }

    /**
     * Initiates an ExpressCheckout payment process
     *
     * Optional parameters can be found here:
     * https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_r_SetExpressCheckout
     *
     * @param float $amount
     * @param string $returnUrl
     * @param string $cancelUrl
     * @param array $optionalParameters
     * @return Response
     */
    public function requestSetExpressCheckout($amount, $returnUrl, $cancelUrl, array $optionalParameters = array())
    {
        return $this->sendApiRequest(array_merge($optionalParameters, array(
            'METHOD' => 'SetExpressCheckout',
            'PAYMENTREQUEST_0_AMT' => $amount,
            'RETURNURL' => $returnUrl,
            'CANCELURL' => $cancelUrl,
        )));
    }

    public function requestGetExpressCheckoutDetails($token)
    {
        return $this->sendApiRequest(array(
            'METHOD' => 'GetExpressCheckoutDetails',
            'TOKEN'  => $token,
        ));
    }

    public function requestGetTransactionDetails($transactionId)
    {
        return $this->sendApiRequest(array(
            'METHOD' => 'GetTransactionDetails',
            'TRANSACTIONID' => $transactionId,
        ));
    }

    public function requestRefundTransaction($transactionId, array $optionalParameters = array())
    {
        return $this->sendApiRequest(array_merge($optionalParameters, array(
            'METHOD' => 'RefundTransaction',
            'TRANSACTIONID' => $transactionId
        )));
    }

    public function sendApiRequest(array $parameters)
    {
        // include some default parameters
        $parameters['VERSION'] = self::API_VERSION;

        // setup request, and authenticate it
        $request = new Request(
            $this->authenticationStrategy->getApiEndpoint($this->isDebug()),
            'POST',
            $parameters
        );
        $this->authenticationStrategy->authenticate($request);

        $response = $this->request($request);
        if (200 !== $response->getStatus()) {
            throw new CommunicationException('The API request was not successful (Status: '.$response->getStatus().'): '.$response->getContent());
        }

        $parameters = array();
        parse_str($response->getContent(), $parameters);

        $paypalResponse = new Response($parameters);
        if (false === $paypalResponse->isSuccess()) {
            $ex = new FinancialException('PayPal-Response was not successful: '.$paypalResponse);
            $ex->setFinancialTransaction($this->currentTransaction);
            $this->currentTransaction->setResponseCode($paypalResponse->body->get('ACK'));
            $this->currentTransaction->setReasonCode($paypalResponse->body->get('L_ERRORCODE0'));

            throw $ex;
        }

        return $paypalResponse;
    }
}