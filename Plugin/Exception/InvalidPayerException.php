<?php

namespace Bundle\JMS\Payment\PayPalPaymentBundle\Plugin\Exception;

use Bundle\JMS\Payment\CorePaymentBundle\Plugin\Exception\FinancialException;

/**
 * This exception is thrown when the buyer is not in desired state.
 * 
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class InvalidPayerException extends FinancialException
{
}