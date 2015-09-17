<?php

namespace JMS\Payment\PaypalBundle\Plugin;

use JMS\Payment\CoreBundle\Entity\FinancialTransaction;
use JMS\Payment\CoreBundle\Model\ExtendedDataInterface;
use JMS\Payment\CoreBundle\Model\FinancialTransactionInterface;
use JMS\Payment\CoreBundle\Plugin\PluginInterface;
use JMS\Payment\CoreBundle\Plugin\AbstractPlugin;
use JMS\Payment\CoreBundle\Plugin\Exception\PaymentPendingException;
use JMS\Payment\CoreBundle\Plugin\Exception\FinancialException;
use JMS\Payment\CoreBundle\Plugin\Exception\Action\VisitUrl;
use JMS\Payment\CoreBundle\Plugin\Exception\ActionRequiredException;
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

    const ERROR_CODE_HONOR_WINDOW = 10617; 
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
     * @var \JMS\Payment\PaypalBundle\Client\Client
     */
    protected $client;

    /**
     * A function that, when given a FinancialTransactionInterface object, provides a credentials key to use.
     *
     * @var callable
     */
    private $credentialsKeyResolver;

    /**
     * @param string $returnUrl
     * @param string $cancelUrl
     * @param \JMS\Payment\PaypalBundle\Client\Client $client
     * @param string $notifyUrl
     */
    public function __construct($returnUrl, $cancelUrl, Client $client, $notifyUrl = null)
    {
        $this->client = $client;
        $this->returnUrl = $returnUrl;
        $this->cancelUrl = $cancelUrl;
        $this->notifyUrl = $notifyUrl;
        $this->credentialsKeyResolver = function () {
            return null;
        };
    }

    public function approve(FinancialTransactionInterface $transaction, $retry)
    {
        if($transaction->getExtendedData()->has('ipn_decision')) {
            $this->updateIpnTransaction($transaction);
        }

        $this->createCheckoutBillingAgreement($transaction, 'Authorization');
    }

    public function approveAndDeposit(FinancialTransactionInterface $transaction, $retry)
    {
        if($transaction->getExtendedData()->has('ipn_decision')) {
            $this->updateIpnTransaction($transaction);
        }

        $this->createCheckoutBillingAgreement($transaction, 'Sale');
    }

    public function updateIpnTransaction(FinancialTransactionInterface $transaction)
    {
        $data = $transaction->getExtendedData();
        $decision = $data->get('ipn_decision');

        if($decision === 'Completed') {
            $transaction->setProcessedAmount($transaction->getRequestedAmount());
            $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
            $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);

            return;
        } else {
            $ex = new FinancialException('PaymentStatus is not completed: Denied');
            $ex->setFinancialTransaction($transaction);
            $transaction->setResponseCode($decision);
            $transaction->setReasonCode($decision);

            throw $ex;
        }
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

        //pull the appropriate transaction id for the refund request depending on how the capture was originally made
        if ($approveTransaction->getTransactionType() === FinancialTransactionInterface::TRANSACTION_TYPE_APPROVE_AND_DEPOSIT) {
            $transactionId = $approveTransaction->getReferenceNumber();
        } else {
            $depositTransaction = $transaction->getCredit()->getPayment()->getDepositTransactions()->first();
            $transactionId = $depositTransaction->getReferenceNumber();
        }

        $response = $this->client->requestRefundTransaction($transactionId, $parameters, $this->getCredentialsKeyForTransaction($transaction));
        $this->saveResponseDetails($data, $response);
        $this->throwUnlessSuccessResponse($response, $transaction);

        $transaction->setReferenceNumber($response->body->get('REFUNDTRANSACTIONID'));
        $transaction->setProcessedAmount($response->body->get('GROSSREFUNDAMT'));
        $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
    }

    public function reApprove(FinancialTransactionInterface $transaction)
    {
        $originalAuthorizationId = $transaction->getPayment()->getApproveTransaction()->getReferenceNumber();
        $response = $this->client->requestDoReauthorization($originalAuthorizationId, $transaction->getRequestedAmount(), [
            'CURRENCYCODE' => $transaction->getPayment()->getPaymentInstruction()->getCurrency()
        ]);

        $credentialsKey = $this->getCredentialsKeyForTransaction($transaction);

        if ($response->body->get('ACK') == 'Success') {
            //GEt the new AuthorizationId and update the transaction
            $extendedData = $transaction->getExtendedData();
            //save the original_authorization_id for reference.
            $extendedData->set('original_authorization_id', $originalAuthorizationId);
            $newAuthorizationId = $response->body->get('AUTHORIZATIONID');
            $transaction->setReferenceNumber($newAuthorizationId);
            $transaction->setExtendedData($extendedData);
            $details = $this->client->requestGetTransactionDetails($newAuthorizationId, $credentialsKey);
            $this->throwUnlessSuccessResponse($details, $transaction);

            switch ($details->body->get('PAYMENTSTATUS')) {
                case 'Completed':
                    break;

                case 'Pending':
                    //This exception should be trow just if the reason of the 'pending state' is different to 'authorization state'
                    if ($response->body->get('PENDINGREASON') != 'authorization') {
                        throw new PaymentPendingException('Payment is still pending: '.$response->body->get('PENDINGREASON'));
                    }
                    break;
                default:
                    $ex = new FinancialException('PaymentStatus is not completed: '.$response->body->get('PAYMENTSTATUS'));
                    $ex->setFinancialTransaction($transaction);
                    $transaction->setResponseCode('Failed');
                    $transaction->setReasonCode($response->body->get('PAYMENTSTATUS'));

                    throw $ex;
            }
        } elseif ($response->body->get('L_ERRORCODE0') == self::ERROR_CODE_HONOR_WINDOW) {
            // if the re-authorization is not allowed inside the honor period, then do nothing and use the original authorization ID
            return;
        } else {
            $this->throwUnlessSuccessResponse($response, $transaction);
        }
    }

    public function deposit(FinancialTransactionInterface $transaction, $retry)
    {
        $data = $transaction->getExtendedData();
        $authorizationId = $transaction->getPayment()->getApproveTransaction()->getReferenceNumber();
        
        //always 'Complete' as we are only capturing once, thus we always indicate that the authorisation is closed
        $completeType = 'Complete';

        $credentialsKey = $this->getCredentialsKeyForTransaction($transaction);

        $response = $this->client->requestDoCapture($authorizationId, $transaction->getRequestedAmount(), $completeType, array(
            'CURRENCYCODE' => $transaction->getPayment()->getPaymentInstruction()->getCurrency(),
        ), $credentialsKey);
        //set reference to that of the deposit transaction ID, this can then be used for credit's later
        $transaction->setReferenceNumber($response->body->get('TRANSACTIONID'));

        $this->saveResponseDetails($data, $response);
        $this->throwUnlessSuccessResponse($response, $transaction);

        $captureAmt = ($response->body->get('AMT'));

        $details = $this->client->requestGetTransactionDetails($authorizationId, $credentialsKey);
        $this->throwUnlessSuccessResponse($details, $transaction);


        switch ($details->body->get('PAYMENTSTATUS')) {
            case 'Completed':
                break;

            case 'Pending':
                 //This exception should be trow just if the reason of the 'pending state' is different to 'authorization state'
                if ($response->body->get('PENDINGREASON')!='authorization') {
                    throw new PaymentPendingException('Payment is still pending: '.$response->body->get('PENDINGREASON'));
                }
                break;
            default:
                $ex = new FinancialException('PaymentStatus is not completed: '.$response->body->get('PAYMENTSTATUS'));
                $ex->setFinancialTransaction($transaction);
                $transaction->setResponseCode('Failed');
                $transaction->setReasonCode($response->body->get('PAYMENTSTATUS'));

                throw $ex;
        }

        $transaction->setProcessedAmount($captureAmt);
        $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
        $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);
    }

    public function reverseApproval(FinancialTransactionInterface $transaction, $retry)
    {
        $data = $transaction->getExtendedData();

        $response = $this->client->requestDoVoid($data->get('authorization_id'), [], $this->getCredentialsKeyForTransaction($transaction));
        $this->throwUnlessSuccessResponse($response, $transaction);

        $transaction->setProcessedAmount($transaction->getRequestedAmount());
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

    public function setCredentialsKeyResolver(callable $resolver)
    {
        $this->credentialsKeyResolver = $resolver;
    }

    protected function createCheckoutBillingAgreement(FinancialTransactionInterface $transaction, $paymentAction)
    {
        $data = $transaction->getExtendedData();

        $token = $this->obtainExpressCheckoutToken($transaction, $paymentAction);

        $credentialsKey = $this->getCredentialsKeyForTransaction($transaction);

        $details = $this->client->requestGetExpressCheckoutDetails($token, $credentialsKey);
        $this->saveResponseDetails($data, $details);
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
                $actionRequest = new ActionRequiredException('User has not yet authorized the transaction.');
                $actionRequest->setFinancialTransaction($transaction);
                $actionRequest->setAction(new VisitUrl($this->client->getAuthenticateExpressCheckoutTokenUrl($token)));

                throw $actionRequest;
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
            $optionalParameters,
            $credentialsKey
        );
        $this->saveResponseDetails($data, $response);
        $this->throwUnlessSuccessResponse($response, $transaction);

        switch($response->body->get('PAYMENTINFO_0_PAYMENTSTATUS')) {
            case 'Completed':
                break;

            case 'Pending':
                //This exception should be trow just if the reason of the 'pending state' is different to 'authorization state'
                if ($response->body->get('PAYMENTINFO_0_PENDINGREASON')!='authorization') {
                    $transaction->setReferenceNumber($response->body->get('PAYMENTINFO_0_TRANSACTIONID'));
                    throw new PaymentPendingException('Payment is still pending: '.$response->body->get('PAYMENTINFO_0_PENDINGREASON'));
                }
                break;
            default:
                $ex = new FinancialException('PaymentStatus is not completed: '.$response->body->get('PAYMENTINFO_0_PAYMENTSTATUS'));
                $ex->setFinancialTransaction($transaction);
                $transaction->setResponseCode('Failed');
                $transaction->setReasonCode($response->body->get('PAYMENTINFO_0_PAYMENTSTATUS'));

                //Set attention required on payment or credit
                if (null !== $transaction->getPayment()) {
                    $transaction->getPayment()->setAttentionRequired(true);
                } elseif (null !== $transaction->getCredit()) {
                    $transaction->getCredit()->setAttentionRequired(true);
                }

                throw $ex;
        }

        $transaction->setReferenceNumber($response->body->get('PAYMENTINFO_0_TRANSACTIONID'));
        $transaction->setProcessedAmount($response->body->get('PAYMENTINFO_0_AMT'));
        $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
        $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);
    }

    /**
     * @param \JMS\Payment\CoreBundle\Model\FinancialTransactionInterface $transaction
     * @param string $paymentAction
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

        $credentialsKey = $this->getCredentialsKeyForTransaction($transaction);

        $response = $this->client->requestSetExpressCheckout(
            $transaction->getRequestedAmount(),
            $this->getReturnUrl($data),
            $this->getCancelUrl($data),
            $opts,
            $credentialsKey
        );
        $this->saveResponseDetails($data, $response);
        $this->throwUnlessSuccessResponse($response, $transaction);

        $data->set('express_checkout_token', $response->body->get('TOKEN'));

        $authenticateTokenUrl = $this->client->getAuthenticateExpressCheckoutTokenUrl($response->body->get('TOKEN'));

        $actionRequest = new ActionRequiredException('User must authorize the transaction.');
        $actionRequest->setFinancialTransaction($transaction);
        $actionRequest->setAction(new VisitUrl($authenticateTokenUrl));

        throw $actionRequest;
    }

    /**
     * @param \JMS\Payment\CoreBundle\Model\FinancialTransactionInterface $transaction
     * @param \JMS\Payment\PaypalBundle\Client\Response $response
     * @return null
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

    protected function getReturnUrl(ExtendedDataInterface $data)
    {
        if ($data->has('return_url')) {
            return $data->get('return_url');
        }
        else if (0 !== strlen($this->returnUrl)) {
            return $this->returnUrl;
        }

        throw new \RuntimeException('You must configure a return url.');
    }

    protected function getCancelUrl(ExtendedDataInterface $data)
    {
        if ($data->has('cancel_url')) {
            return $data->get('cancel_url');
        }
        else if (0 !== strlen($this->cancelUrl)) {
            return $this->cancelUrl;
        }

        throw new \RuntimeException('You must configure a cancel url.');
    }

    protected function getNotifyUrl(ExtendedDataInterface $data)
    {
        if ($data->has('notify_url')) {
            return $data->get('notify_url');
        }
        else if (0 !== strlen($this->notifyUrl)) {
            return $this->notifyUrl;
        }
    }

    private function saveResponseDetails(ExtendedDataInterface $data, Response $details)
    {
        foreach ($details->body->all() as $key => $value) {
            $data->set($key, $value);
        }
    }

    private function getCredentialsKeyForTransaction(FinancialTransactionInterface $transaction)
    {
        return call_user_func($this->credentialsKeyResolver, $transaction);
    }
}
