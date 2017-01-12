<?php

namespace JMS\Payment\PaypalBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Type for Paypal Express Checkout.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class ExpressCheckoutType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
    }

    public function getName()
    {
        return 'paypal_express_checkout';
    }
}
