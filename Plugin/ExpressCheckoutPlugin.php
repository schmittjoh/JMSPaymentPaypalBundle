<?php

namespace Bundle\PayPalPaymentBundle\Plugin;

use Bundle\PaymentBundle\Util\Number;
use Bundle\PaymentBundle\Plugin\Exception\PaymentPendingException;
use Bundle\PaymentBundle\Plugin\Exception\BlockedException;
use Bundle\PaymentBundle\Plugin\PluginInterface;
use Bundle\PaymentBundle\Plugin\Exception\FinancialException;
use Bundle\PaymentBundle\Plugin\Exception\Action\VisitUrl;
use Bundle\PaymentBundle\Plugin\Exception\ActionRequiredException;
use Bundle\PayPalPaymentBundle\Authentication\AuthenticationStrategyInterface;
use Bundle\PaymentBundle\Model\FinancialTransactionInterface;

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
        $this->currentTransaction = $transaction;
        $data = $transaction->getExtendedData();
        
        // generate an express token if none exists, yet
        if (false === $data->has('express_checkout_token')) {
            $response = $this->requestSetExpressCheckout(
                $transaction->getRequestedAmount(),
                $this->returnUrl,
                $this->cancelUrl,
                array(
                	'PAYMENTREQUEST_0_PAYMENTACTION' => 'Authorization',
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
        $response = $this->requestDoExpressCheckoutPayment($data->get('express_checkout_token'), $transaction->getRequestedAmount(), 'Authorization', $details->body->get('PAYERID'));
        
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
        
        $transaction->setProcessedAmount($response->body->get('PAYMENTINFO_0_AMT'));
        $transaction->setReferenceNumber($response->body->get('PAYMENTINFO_0_TRANSACTIONID'));
        $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
    }
    
    public function deposit(FinancialTransactionInterface $transaction, $retry)
    {
        $this->currentTransaction = $transaction;
        $data = $transaction->getExtendedData();
        
        if (Number::compare($transaction->getPayment()->getApprovedAmount(), $transaction->getRequestedAmount()) === 0) {
            $completeType = 'Complete';
        }
        else {
            $completeType = 'NotComplete';
        }
        
        $this->requestDoCapture($transaction->getReferenceNumber(), $transaction->getRequestedAmount(), $completeType, array(
            'CURRENCYCODE' => $transaction->getPayment()->getPaymentInstruction()->getCurrency(),
        ));
        $details = $this->requestGetTransactionDetails($transaction->getReferenceNumber());
        
        switch ($details->body->get('PAYMENTSTATUS')) {
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
        
        $transaction->setProcessedAmount($details->body->get('AMT'));
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
    
    protected function getExpressUrl($token)
    {
        $host = $this->isDebug() ? 'www.sandbox.paypal.com' : 'www.paypal.com';

        return sprintf(
            'https://%s/cgi-bin/webscr?cmd=_express-checkout&token=%s',
            $host, 
            $token
        );
    }
}