<?php

namespace Bundle\PayPalPaymentBundle\Plugin;

use Bundle\PaymentBundle\Model\CreditInterface;

use Bundle\PaymentBundle\Model\PaymentInterface;

use Bundle\PaymentBundle\Plugin\Exception\FunctionNotSupportedException;

use Bundle\PaymentBundle\Model\PaymentInstructionInterface;

use Bundle\PayPalPaymentBundle\Gateway\Response;

use Bundle\PayPalPaymentBundle\Gateway\ErrorResponse;

use Bundle\PaymentBundle\Plugin\QueryablePluginInterface;
use Bundle\PaymentBundle\BrowserKit\Request;
use Bundle\PaymentBundle\Plugin\GatewayPlugin;
use Bundle\PayPalPaymentBundle\Authentication\AuthenticationStrategyInterface;
use Bundle\PayPalPaymentBundle\Plugin\Exception\InvalidPayerException;
use Bundle\PaymentBundle\Entity\FinancialTransaction;
use Bundle\PaymentBundle\Plugin\Exception\FinancialException;
use Bundle\PaymentBundle\Plugin\Exception\InternalErrorException;
use Bundle\PaymentBundle\Plugin\Exception\CommunicationException;
use Bundle\PaymentBundle\Model\FinancialTransactionInterface;

/**
 * Implements the NVP API but does not perform any actual transactions
 * 
 * @see https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/howto_api_reference
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
abstract class PayPalPlugin extends GatewayPlugin
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