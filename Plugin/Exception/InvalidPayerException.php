<?php

namespace Bundle\PayPalPaymentBundle\Plugin\Exception;

use Bundle\PaymentBundle\Plugin\Exception\FinancialException;

/**
 * This exception is thrown when the buyer is not in desired state.
 * 
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class InvalidPayerException extends FinancialException
{
}