<?php

namespace Bundle\PayPalPaymentBundle\Plugin;

use Bundle\PaymentBundle\Model\ExtendedDataInterface;
use Bundle\PaymentBundle\Model\FinancialTransactionInterface;
use Bundle\PaymentBundle\Plugin\Exception\PaymentPendingException;
use Bundle\PaymentBundle\Plugin\Exception\BlockedException;
use Bundle\PaymentBundle\Plugin\PluginInterface;
use Bundle\PaymentBundle\Plugin\Exception\FinancialException;
use Bundle\PaymentBundle\Plugin\Exception\Action\VisitUrl;
use Bundle\PaymentBundle\Plugin\Exception\ActionRequiredException;
use Bundle\PaymentBundle\Util\Number;
use Bundle\PayPalPaymentBundle\Authentication\AuthenticationStrategyInterface;

class ExpressCheckoutPlugin extends PayPalPlugin
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
        
    public function approveAndDeposit($transaction, $retry)
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
            $parameters['AMT'] = $transaction->getRequestedAmount();
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
        
        // generate an express token if none exists, yet
        if (false === $data->has('express_checkout_token')) {
            $response = $this->requestSetExpressCheckout(
                $transaction->getRequestedAmount(),
                $this->getReturnUrl($data),
                $this->getCancelUrl($data),
                array(
              	    'PAYMENTREQUEST_0_PAYMENTACTION' => $paymentAction,
                    'PAYMENTREQUEST_0_CURRENCYCODE'  => $transaction->getPayment()->getPaymentInstruction()->getCurrency(),
                )
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
                
            default:
                $actionRequest = new ActionRequiredException('User has not yet authorized the transaction.');
                $actionRequest->setFinancialTransaction($transaction);
                $actionRequest->setAction(new VisitUrl($this->getExpressUrl($data->get('express_checkout_token'))));
                
                throw $actionRequest;
        }
        
        // complete the transaction
        $data->set('paypal_payer_id', $details->body->get('PAYERID'));
        $response = $this->requestDoExpressCheckoutPayment($data->get('express_checkout_token'), $transaction->getRequestedAmount(), $paymentAction, $details->body->get('PAYERID'));
        
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
        if ($data->has('return_url')) {
            return $data->get('return_url');
        }
        else if (0 !== strlen($this->cancelUrl)) {
            return $this->cancelUrl;
        }
        
        throw new \RuntimeException('You must configure a cancel url.');
    }
}