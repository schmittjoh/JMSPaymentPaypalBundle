<?php

namespace JMS\Payment\PaypalBundle\Plugin;

use JMS\Payment\CoreBundle\Model\ExtendedDataInterface;
use JMS\Payment\CoreBundle\Model\FinancialTransactionInterface;
use JMS\Payment\CoreBundle\Plugin\AbstractPlugin;
use JMS\Payment\CoreBundle\Plugin\Exception\Action\VisitUrl;
use JMS\Payment\CoreBundle\Plugin\Exception\ActionRequiredException;
use JMS\Payment\CoreBundle\Plugin\Exception\FinancialException;
use JMS\Payment\CoreBundle\Plugin\Exception\PaymentPendingException;
use JMS\Payment\CoreBundle\Plugin\PluginInterface;
use JMS\Payment\CoreBundle\Util\Number;
use JMS\Payment\PaypalBundle\Client\Client;
use JMS\Payment\PaypalBundle\Client\Response;

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

class ExpressCheckoutPlugin extends AbstractPlugin
{
    /**
     * @var string
     */
    protected $returnUrl;

    /**
     * @var string
     */
    protected $cancelUrl;

    /**
     * @var string
     */
    protected $notifyUrl;

    /**
     * @var string
     */
    protected $userAction;

    /**
     * @var \JMS\Payment\PaypalBundle\Client\Client
     */
    protected $client;

    /**
     * @param string                                  $returnUrl
     * @param string                                  $cancelUrl
     * @param \JMS\Payment\PaypalBundle\Client\Client $client
     * @param string                                  $notifyUrl
     * @param string                                  $userAction
     */
    public function __construct($returnUrl, $cancelUrl, Client $client, $notifyUrl = null, $userAction = null)
    {
        $this->client = $client;
        $this->returnUrl = $returnUrl;
        $this->cancelUrl = $cancelUrl;
        $this->notifyUrl = $notifyUrl;
        $this->userAction = $userAction;
    }

    public function approve(FinancialTransactionInterface $transaction, $retry)
    {
        $this->createCheckoutBillingAgreement($transaction, 'Authorization');
    }

    public function approveAndDeposit(FinancialTransactionInterface $transaction, $retry)
    {
        $this->createCheckoutBillingAgreement($transaction, 'Sale');
    }

    public function credit(FinancialTransactionInterface $transaction, $retry)
    {
        $data = $transaction->getExtendedData();
        $approveTransaction = $transaction->getCredit()->getPayment()->getApproveTransaction();

        $parameters = array();
        if (Number::compare($transaction->getRequestedAmount(), $approveTransaction->getProcessedAmount()) !== 0) {
            $parameters['REFUNDTYPE'] = 'Partial';
            $parameters['AMT'] = $this->client->convertAmountToPaypalFormat($transaction->getRequestedAmount());
            $parameters['CURRENCYCODE'] = $transaction->getCredit()->getPaymentInstruction()->getCurrency();
        }

        $response = $this->client->requestRefundTransaction($data->get('authorization_id'), $parameters);

        $this->throwUnlessSuccessResponse($response, $transaction);

        $transaction->setReferenceNumber($response->body->get('REFUNDTRANSACTIONID'));
        $transaction->setProcessedAmount($response->body->get('NETREFUNDAMT'));
        $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
    }

    public function deposit(FinancialTransactionInterface $transaction, $retry)
    {
        $data = $transaction->getExtendedData();
        $authorizationId = $transaction->getPayment()->getApproveTransaction()->getReferenceNumber();

        if (Number::compare($transaction->getPayment()->getApprovedAmount(), $transaction->getRequestedAmount()) === 0) {
            $completeType = 'Complete';
        } else {
            $completeType = 'NotComplete';
        }

        $response = $this->client->requestDoCapture($authorizationId, $transaction->getRequestedAmount(), $completeType, array(
            'CURRENCYCODE' => $transaction->getPayment()->getPaymentInstruction()->getCurrency(),
        ));
        $this->throwUnlessSuccessResponse($response, $transaction);

        $details = $this->client->requestGetTransactionDetails($authorizationId);
        $this->throwUnlessSuccessResponse($details, $transaction);

        switch ($details->body->get('PAYMENTSTATUS')) {
            case 'Completed':
                break;

            case 'Pending':
                throw new PaymentPendingException('Payment is still pending: '.$response->body->get('PENDINGREASON'));

            default:
                $ex = new FinancialException('PaymentStatus is not completed: '.$response->body->get('PAYMENTSTATUS'));
                $ex->setFinancialTransaction($transaction);
                $transaction->setResponseCode('Failed');
                $transaction->setReasonCode($response->body->get('PAYMENTSTATUS'));

                throw $ex;
        }

        $transaction->setReferenceNumber($authorizationId);
        $transaction->setProcessedAmount($details->body->get('AMT'));
        $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
        $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);
    }

    public function reverseApproval(FinancialTransactionInterface $transaction, $retry)
    {
        $data = $transaction->getExtendedData();

        $response = $this->client->requestDoVoid($data->get('authorization_id'));
        $this->throwUnlessSuccessResponse($response, $transaction);

        $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
    }

    public function processes($paymentSystemName)
    {
        return 'paypal_express_checkout' === $paymentSystemName;
    }

    public function isIndependentCreditSupported()
    {
        return false;
    }

    protected function createCheckoutBillingAgreement(FinancialTransactionInterface $transaction, $paymentAction)
    {
        $data = $transaction->getExtendedData();

        $token = $this->obtainExpressCheckoutToken($transaction, $paymentAction);

        $details = $this->client->requestGetExpressCheckoutDetails($token);
        $this->throwUnlessSuccessResponse($details, $transaction);

        // verify checkout status
        switch ($details->body->get('CHECKOUTSTATUS')) {
            case 'PaymentActionFailed':
                $ex = new FinancialException('PaymentAction failed.');
                $transaction->setResponseCode('Failed');
                $transaction->setReasonCode('PaymentActionFailed');
                $ex->setFinancialTransaction($transaction);

                throw $ex;

            case 'PaymentCompleted':
                break;

            case 'PaymentActionNotInitiated':
                break;

            default:
                $this->throwActionRequired($token, $data, $transaction);
        }

        // complete the transaction
        $data->set('paypal_payer_id', $details->body->get('PAYERID'));

        $optionalParameters = array(
            'PAYMENTREQUEST_0_CURRENCYCODE' => $transaction->getPayment()->getPaymentInstruction()->getCurrency(),
        );

        if (null !== $notifyUrl = $this->getNotifyUrl($data)) {
            $optionalParameters['PAYMENTREQUEST_0_NOTIFYURL'] = $notifyUrl;
        }

        $response = $this->client->requestDoExpressCheckoutPayment(
            $data->get('express_checkout_token'),
            $transaction->getRequestedAmount(),
            $paymentAction,
            $details->body->get('PAYERID'),
            $optionalParameters
        );
        $this->throwUnlessSuccessResponse($response, $transaction);

        switch ($response->body->get('PAYMENTINFO_0_PAYMENTSTATUS')) {
            case 'Completed':
                break;

            case 'Pending':
                $transaction->setReferenceNumber($response->body->get('PAYMENTINFO_0_TRANSACTIONID'));

                throw new PaymentPendingException('Payment is still pending: '.$response->body->get('PAYMENTINFO_0_PENDINGREASON'));

            default:
                $ex = new FinancialException('PaymentStatus is not completed: '.$response->body->get('PAYMENTINFO_0_PAYMENTSTATUS'));
                $ex->setFinancialTransaction($transaction);
                $transaction->setResponseCode('Failed');
                $transaction->setReasonCode($response->body->get('PAYMENTINFO_0_PAYMENTSTATUS'));

                throw $ex;
        }

        $transaction->setReferenceNumber($response->body->get('PAYMENTINFO_0_TRANSACTIONID'));
        $transaction->setProcessedAmount($response->body->get('PAYMENTINFO_0_AMT'));
        $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
        $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);
    }

    /**
     * @param \JMS\Payment\CoreBundle\Model\FinancialTransactionInterface $transaction
     * @param string                                                      $paymentAction
     *
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\ActionRequiredException if user has to authenticate the token
     *
     * @return string
     */
    protected function obtainExpressCheckoutToken(FinancialTransactionInterface $transaction, $paymentAction)
    {
        $data = $transaction->getExtendedData();
        if ($data->has('express_checkout_token')) {
            return $data->get('express_checkout_token');
        }

        $opts = $data->has('checkout_params') ? $data->get('checkout_params') : array();
        $opts['PAYMENTREQUEST_0_PAYMENTACTION'] = $paymentAction;
        $opts['PAYMENTREQUEST_0_CURRENCYCODE'] = $transaction->getPayment()->getPaymentInstruction()->getCurrency();

        $response = $this->client->requestSetExpressCheckout(
            $transaction->getRequestedAmount(),
            $this->getReturnUrl($data),
            $this->getCancelUrl($data),
            $opts
        );
        $this->throwUnlessSuccessResponse($response, $transaction);

        $data->set('express_checkout_token', $response->body->get('TOKEN'));

        $this->throwActionRequired($response->body->get('TOKEN'), $data, $transaction);
    }

    /**
     * @param \JMS\Payment\CoreBundle\Model\FinancialTransactionInterface $transaction
     * @param \JMS\Payment\PaypalBundle\Client\Response                   $response
     *
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\FinancialException
     */
    protected function throwUnlessSuccessResponse(Response $response, FinancialTransactionInterface $transaction)
    {
        if ($response->isSuccess()) {
            return;
        }

        $transaction->setResponseCode($response->body->get('ACK'));
        $transaction->setReasonCode($response->body->get('L_ERRORCODE0'));

        $ex = new FinancialException('PayPal-Response was not successful: '.$response);
        $ex->setFinancialTransaction($transaction);

        throw $ex;
    }

    /**
     * @param string                                                      $token
     * @param \JMS\Payment\CoreBundle\Model\ExtendedDataInterface         $data
     * @param \JMS\Payment\CoreBundle\Model\FinancialTransactionInterface $transaction
     *
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\ActionRequiredException
     */
    protected function throwActionRequired($token, $data, $transaction)
    {
        $ex = new ActionRequiredException('User must authorize the transaction.');
        $ex->setFinancialTransaction($transaction);

        $params = array();

        if ($useraction = $this->getUserAction($data)) {
            $params['useraction'] = $this->getUserAction($data);
        }

        $ex->setAction(new VisitUrl(
            $this->client->getAuthenticateExpressCheckoutTokenUrl($token, $params)
        ));

        throw $ex;
    }

    protected function getReturnUrl(ExtendedDataInterface $data)
    {
        if ($data->has('return_url')) {
            return $data->get('return_url');
        } elseif (!empty($this->returnUrl)) {
            return $this->returnUrl;
        }

        throw new \RuntimeException('You must configure a return url.');
    }

    protected function getCancelUrl(ExtendedDataInterface $data)
    {
        if ($data->has('cancel_url')) {
            return $data->get('cancel_url');
        } elseif (!empty($this->cancelUrl)) {
            return $this->cancelUrl;
        }

        throw new \RuntimeException('You must configure a cancel url.');
    }

    protected function getNotifyUrl(ExtendedDataInterface $data)
    {
        if ($data->has('notify_url')) {
            return $data->get('notify_url');
        } elseif (!empty($this->notifyUrl)) {
            return $this->notifyUrl;
        }
    }

    protected function getUserAction(ExtendedDataInterface $data)
    {
        if ($data->has('useraction')) {
            return $data->get('useraction');
        } elseif (!empty($this->userAction)) {
            return $this->userAction;
        }
    }
}
