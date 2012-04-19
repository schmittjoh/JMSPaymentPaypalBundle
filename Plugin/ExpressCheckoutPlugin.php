<?php

namespace JMS\Payment\PaypalBundle\Plugin;

use JMS\Payment\CoreBundle\Model\ExtendedDataInterface;
use JMS\Payment\CoreBundle\Model\FinancialTransactionInterface;
use JMS\Payment\CoreBundle\Plugin\Exception\PaymentPendingException;
use JMS\Payment\CoreBundle\Plugin\Exception\BlockedException;
use JMS\Payment\CoreBundle\Plugin\PluginInterface;
use JMS\Payment\CoreBundle\Plugin\Exception\FinancialException;
use JMS\Payment\CoreBundle\Plugin\Exception\Action\VisitUrl;
use JMS\Payment\CoreBundle\Plugin\Exception\ActionRequiredException;
use JMS\Payment\CoreBundle\Util\Number;
use JMS\Payment\PaypalBundle\Authentication\AuthenticationStrategyInterface;

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

class ExpressCheckoutPlugin extends PaypalPlugin
{
    protected $returnUrl;
    protected $cancelUrl;

    public function __construct($returnUrl, $cancelUrl, AuthenticationStrategyInterface $authenticationStrategy, $isDebug)
    {
        parent::__construct($authenticationStrategy, $isDebug);

        $this->returnUrl = $returnUrl;
        $this->cancelUrl = $cancelUrl;
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
        $this->currentTransaction = $transaction;
        $data = $transaction->getExtendedData();
        $approveTransaction = $transaction->getCredit()->getPayment()->getApproveTransaction();

        $parameters = array();
        if (Number::compare($transaction->getRequestedAmount(), $approveTransaction->getProcessedAmount()) !== 0) {
            $parameters['REFUNDTYPE'] = 'Partial';
            $parameters['AMT'] = $this->convertAmountToPaypalFormat($transaction->getRequestedAmount());
            $parameters['CURRENCYCODE'] = $transaction->getCredit()->getPaymentInstruction()->getCurrency();
        }

        $response = $this->requestRefundTransaction($data->get('authorization_id'), $parameters);

        $transaction->setReferenceNumber($response->body->get('REFUNDTRANSACTIONID'));
        $transaction->setProcessedAmount($response->body->get('NETREFUNDAMT'));
        $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
    }

    public function deposit(FinancialTransactionInterface $transaction, $retry)
    {
        $this->currentTransaction = $transaction;
        $data = $transaction->getExtendedData();
        $authorizationId = $transaction->getPayment()->getApproveTransaction()->getReferenceNumber();

        if (Number::compare($transaction->getPayment()->getApprovedAmount(), $transaction->getRequestedAmount()) === 0) {
            $completeType = 'Complete';
        }
        else {
            $completeType = 'NotComplete';
        }

        $this->requestDoCapture($authorizationId, $transaction->getRequestedAmount(), $completeType, array(
            'CURRENCYCODE' => $transaction->getPayment()->getPaymentInstruction()->getCurrency(),
        ));
        $details = $this->requestGetTransactionDetails($authorizationId);

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

        $this->requestDoVoid($data->get('authorization_id'));

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
        $this->currentTransaction = $transaction;
        $data = $transaction->getExtendedData();

        $opts = $data->has('checkout_params') ? $data->get('checkout_params') : array();
        $opts['PAYMENTREQUEST_0_PAYMENTACTION'] = $paymentAction;
        $opts['PAYMENTREQUEST_0_CURRENCYCODE'] = $transaction->getPayment()->getPaymentInstruction()->getCurrency();

        // generate an express token if none exists, yet
        if (false === $data->has('express_checkout_token')) {
            $response = $this->requestSetExpressCheckout(
                $transaction->getRequestedAmount(),
                $this->getReturnUrl($data),
                $this->getCancelUrl($data),
                $opts
            );
            $data->set('express_checkout_token', $response->body->get('TOKEN'));

            $actionRequest = new ActionRequiredException('User must authorize the transaction.');
            $actionRequest->setFinancialTransaction($transaction);
            $actionRequest->setAction(new VisitUrl($this->getExpressUrl($response->body->get('TOKEN'))));

            throw $actionRequest;
        }

        $details = $this->requestGetExpressCheckoutDetails($data->get('express_checkout_token'));
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
                $actionRequest->setAction(new VisitUrl($this->getExpressUrl($data->get('express_checkout_token'))));

                throw $actionRequest;
        }

        // complete the transaction
        $data->set('paypal_payer_id', $details->body->get('PAYERID'));

	// Add the Payment-Details to the extendedData
	$data->set('payment_details' , $details->body->all());

        $response = $this->requestDoExpressCheckoutPayment(
            $data->get('express_checkout_token'),
            $transaction->getRequestedAmount(),
            $paymentAction,
            $details->body->get('PAYERID'),
            array('PAYMENTREQUEST_0_CURRENCYCODE' => $transaction->getPayment()->getPaymentInstruction()->getCurrency())
        );

        switch($response->body->get('PAYMENTINFO_0_PAYMENTSTATUS')) {
            case 'Completed':
                break;

            case 'Pending':
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

    protected function getExpressUrl($token)
    {
        $host = $this->isDebug() ? 'www.sandbox.paypal.com' : 'www.paypal.com';

        return sprintf(
            'https://%s/cgi-bin/webscr?cmd=_express-checkout&token=%s',
            $host,
            $token
        );
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
}
